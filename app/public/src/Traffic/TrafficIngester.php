<?php

namespace App\Traffic;

use App\Entity\Exchange;
use App\Entity\ToolCall;
use App\Entity\UsageRecord;
use App\Repository\ExchangeRepository;
use App\Repository\ToolCallRepository;
use App\Repository\UsageRecordRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Takes CLIProxyAPI's request logs (one file per request in its logs directory) into the database: every finished
 * file becomes an Exchange with its tool calls, results of earlier calls are matched to them, the file is compressed
 * into the archive (kept for good) and removed from the logs directory. main.log, CLIProxyAPI's own log, stays.
 */
class TrafficIngester
{
    private const int MAX_PROMPT = 65_536;
    private const int MAX_TEXT = 262_144;
    private const int MAX_TOOL_INPUT = 32_768;
    private const int MAX_TOOL_OUTPUT = 16_384;
    /** A file younger than this may still be written (streams are logged as they go). */
    private const int SETTLE_SECONDS = 5;
    /** Larger files are archived as a stream and not parsed (a long Codex WebSocket connection can grow without end). */
    private const int MAX_PARSE_BYTES = 256 * 1_048_576;
    /** usage_record_id of an exchange that will not get one (a prewarm, a token count, a record that never came). */
    private const string NOT_LINKED = '0';
    /** log_file prefix of a turn read from a WebSocket connection still open (collectLiveTurns). */
    public const string LIVE = 'live:';
    /** Held while request logs are taken in, re-read or moved: by the collector's traffic steps, reparse and retry. */
    public const string LOCK = 'app-traffic';
    /** MySQL errors of the server, not of the data: gone away, lost connection, too many connections, shutting
     * down, disk or table full, storage engine error, lock wait, deadlock; and an unknown column or table – during a
     * deploy the new code runs for a moment before its migration. */
    private const array TRANSIENT_MYSQL_CODES = [1021, 1030, 1040, 1053, 1054, 1114, 1146, 1205, 1213, 2002, 2003, 2006, 2013];
    /** Attempts on one file before the quarantine: the process died on it this many times. */
    private const int MAX_ATTEMPTS = 2;

    public function __construct(
        #[Autowire('%env(CLIPROXY_LOG_DIR)%')]
        private readonly string $logDirectory,
        #[Autowire('%env(TRAFFIC_ARCHIVE_DIR)%')]
        private readonly string $archiveDirectory,
        private readonly ManagerRegistry $doctrine,
        private readonly ExchangeRepository $exchanges,
        private readonly ToolCallRepository $toolCalls,
        private readonly UsageRecordRepository $usage,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Takes every finished request log, oldest first.
     * - A file whose content cannot be read goes to the quarantine with its error beside it, so it never blocks the
     *   files after it (app:traffic:retry brings it back).
     * - A database or disk error is not the file's fault: the pass stops and the file waits in logs/ for the next one.
     * - A file the process died on twice (memory) goes to the quarantine too: logs/.ingesting remembers the attempts.
     *
     * @return int the request logs taken in
     */
    public function ingest(int $limit = 200): int
    {
        if (!is_dir($this->logDirectory)) {
            return 0;
        }
        $files = [];
        $settled = $this->clock->now()->getTimestamp() - self::SETTLE_SECONDS;
        foreach (new \DirectoryIterator($this->logDirectory) as $file) {
            if ($file->isFile() && 'log' === $file->getExtension() && !str_starts_with($file->getFilename(), 'main')
                && $file->getMTime() <= $settled) {
                $files[$file->getPathname()] = $file->getMTime();
            }
        }
        asort($files);
        $taken = 0;
        foreach (\array_slice(array_keys($files), 0, $limit) as $path) {
            $attempts = $this->startAttempt(basename($path));
            if ($attempts > self::MAX_ATTEMPTS) {
                $this->quarantine($path, new \RuntimeException(\sprintf('The collector died %d times taking this file (memory?)', self::MAX_ATTEMPTS)));
                $this->endAttempt();
                continue;
            }
            try {
                $this->takeFile($path);
                ++$taken;
            } catch (\Throwable $exception) {
                $this->doctrine->resetManager();
                $this->endAttempt();
                if (self::isTransient($exception)) {
                    throw $exception;
                }
                $this->quarantine($path, $exception);
                continue;
            }
            $this->endAttempt();
        }

        return $taken;
    }

    /**
     * One log file into the database: a Codex WebSocket connection gives one exchange per turn, any other file one.
     * The compressed copy is in the archive before the database row, and the file leaves the logs directory only
     * after both. A file too large to read whole is archived as a stream and recorded by its size only.
     *
     * @return list<Exchange>
     */
    public function takeFile(string $path): array
    {
        $name = basename($path);
        $size = (int) filesize($path);
        $writtenAt = (int) filemtime($path);
        if ($size > self::MAX_PARSE_BYTES) {
            $archived = $this->archiveStream($name, $path, $this->clock->now()->setTimestamp($writtenAt));
            $exchanges = [$this->tooLarge($name, $path, $size, $writtenAt, $archived)];
        } else {
            $text = file_get_contents($path);
            if (false === $text) {
                throw new \RuntimeException('Cannot read '.$path);
            }
            $log = RequestLog::parse($text);
            $archived = $this->archive($name, $text, $log->startedAt() ?? $this->clock->now());
            unset($text);
            $exchanges = $this->record($name, $log, $writtenAt, $archived);
        }
        unlink($path);
        $this->doctrine->getManager()->clear();

        return $exchanges;
    }

    /**
     * Reads an archived log again (app:traffic:reparse): its exchanges are rebuilt with today's parser, keeping their
     * tool results and links. The archive is not touched.
     *
     * @return list<Exchange>
     */
    public function reparse(string $archivePath): array
    {
        $path = $this->archiveDirectory.'/'.$archivePath;
        // Only an archive that reads back whole replaces what the journal has: a damaged one would empty it.
        $text = '';
        self::gunzip($path, self::MAX_PARSE_BYTES, static function (string $chunk) use (&$text) {
            $text .= $chunk;
        });
        $name = basename($archivePath, '.gz');
        $exchanges = $this->record($name, RequestLog::parse($text), (int) filemtime($path), $archivePath);
        $this->doctrine->getManager()->clear();

        return $exchanges;
    }

    /**
     * Pieces of request logs CLIProxyAPI never finished (it was stopped mid-stream). A long WebSocket connection's
     * timeline lives in logs/request-log-parts-* until the connection closes, possibly after a quiet hour, so nothing
     * is moved: a directory idle for a day is copied to archive/orphans/<day>/ (once), CLIProxyAPI's own stays.
     *
     * @return int directories copied
     */
    public function collectOrphans(): int
    {
        $moved = 0; // copied
        $idle = $this->clock->now()->getTimestamp() - 86400;
        foreach (glob($this->logDirectory.'/request-log-parts-*', \GLOB_ONLYDIR) ?: [] as $directory) {
            $newest = filemtime($directory) ?: 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $newest = max($newest, $file->getMTime());
            }
            if ($newest > $idle) {
                continue;
            }
            $target = $this->archiveDirectory.'/orphans/'.date('Y-m-d', $newest);
            if (!is_dir($target) && !@mkdir($target, 0o750, true) && !is_dir($target)) {
                throw new ArchiveException('Cannot create '.$target);
            }
            $copy = $target.'/'.basename($directory);
            if (is_dir($copy)) {
                continue;
            }
            // Copied under a temporary name and renamed when whole: a copy cut short is copied over again (copy()
            // overwrites), never taken as done.
            $final = $copy;
            $copy .= '.partial';
            if (!is_dir($copy) && !@mkdir($copy, 0o750) && !is_dir($copy)) {
                throw new ArchiveException('Cannot create '.$copy);
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $destination = $copy.'/'.substr($item->getPathname(), \strlen($directory) + 1);
                if ($item->isDir() ? !@mkdir($destination, 0o750) && !is_dir($destination) : !copy($item->getPathname(), $destination)) {
                    throw new ArchiveException('Cannot copy '.$item->getPathname().' to '.$copy);
                }
            }
            if (!rename($copy, $final)) {
                throw new ArchiveException('Cannot finish the copy '.$final);
            }
            ++$moved;
        }

        return $moved;
    }

    /** @return list<string> archive paths, oldest first (optionally from a day on) */
    public function archived(?string $fromDay = null): array
    {
        $paths = [];
        foreach (glob($this->archiveDirectory.'/*/*.log.gz') ?: [] as $file) {
            $day = basename(\dirname($file));
            if (null === $fromDay || $day >= $fromDay) {
                $paths[] = $day.'/'.basename($file);
            }
        }
        sort($paths);

        return $paths;
    }

    /** Back from quarantine into logs/, for the next pass (app:traffic:retry). @return int files moved */
    public function retryQuarantined(?string $name = null): int
    {
        $moved = 0;
        foreach (glob($this->quarantineDirectory().'/*.log') ?: [] as $file) {
            if (null !== $name && basename($file) !== $name) {
                continue;
            }
            // Its original write time (the request's duration until the usage record links it), and settled.
            $mtime = preg_match('/^mtime: (\d+)$/m', (string) @file_get_contents($file.'.error'), $match) ? (int) $match[1] : (int) filemtime($file);
            if (rename($file, $this->logDirectory.'/'.basename($file))) {
                touch($this->logDirectory.'/'.basename($file), min($mtime, $this->clock->now()->getTimestamp() - self::SETTLE_SECONDS - 1));
                @rename($file.'.error', $file.'.error.'.$this->clock->now()->format('YmdHis'));
                ++$moved;
            }
        }

        return $moved;
    }

    /** @return list<Exchange> */
    private function record(string $name, RequestLog $log, int $writtenAt, string $archived): array
    {
        // A client checking the hub is there (Claude Code: HEAD /api/hello, 404 from CLIProxyAPI) is no traffic: the
        // file is archived like any other, the journal does not list it.
        if (self::isProbe($log)) {
            return [];
        }
        $entityManager = $this->doctrine->getManager();
        $exchanges = [];
        $timeline = $log->sections['WEBSOCKET TIMELINE'][0] ?? null;
        if (null !== $timeline && null === $log->status) {
            foreach (WebsocketTimeline::turns($timeline) as $index => $turn) {
                $exchange = $this->exchangeFor(mb_substr($name, 0, 180).'#'.($index + 1));
                $this->fillWebsocketTurn($exchange, $name, $log, $turn);
                $this->supersedeLive($exchange);
                $this->reenrich($exchange);
                $exchange->archivePath = $archived;
                // Saved turn by turn: a later turn's tool results find the calls of the earlier ones.
                $entityManager->persist($exchange);
                $entityManager->flush();
                $exchanges[] = $exchange;
            }
        }
        if ([] === $exchanges) {
            $exchange = $this->exchangeFor(mb_substr($name, 0, 190));
            $this->fill($exchange, $name, $log, $writtenAt);
            $this->reenrich($exchange);
            if (null !== $timeline) {
                $exchange->endpoint = mb_substr($log->path().' (ws)', 0, 64);
                $exchange->error = $exchange->error ?: 'Ryšys be nė vienos užklausos';
            }
            $exchange->archivePath = $archived;
            $entityManager->persist($exchange);
            $entityManager->flush();
            $exchanges[] = $exchange;
        }

        // An older parser made one exchange of a WebSocket connection: it stays, marked superseded by the turns.
        if (\count($exchanges) > 1 || str_contains($exchanges[0]->logFile, '#')) {
            $whole = $this->exchanges->findOneBy(['logFile' => mb_substr($name, 0, 190)]);
            if (null !== $whole) {
                $whole->kind = Exchange::KIND_SUPERSEDED;
                $entityManager->flush();
            }
        }

        return $exchanges;
    }

    private static function isProbe(RequestLog $log): bool
    {
        return \in_array(strtoupper($log->info['Method'] ?? ''), ['HEAD', 'OPTIONS'], true) || '/api/hello' === $log->path();
    }

    /** A reparsed exchange that was linked gets its usage record's additions back (the log alone lacks them). */
    private function reenrich(Exchange $exchange): void
    {
        if (null === $exchange->usageRecordId || self::NOT_LINKED === $exchange->usageRecordId) {
            return;
        }
        $usage = $this->usage->find($exchange->usageRecordId);
        if (null !== $usage) {
            $this->enrich($exchange, $usage);
        }
    }

    private function tooLarge(string $name, string $path, int $size, int $writtenAt, string $archived): Exchange
    {
        $head = (string) file_get_contents($path, false, null, 0, 65536);
        $log = RequestLog::parse(substr($head, 0, (int) (strpos($head, '=== REQUEST BODY ===') ?: \strlen($head))));
        $exchange = $this->exchangeFor(mb_substr($name, 0, 190));
        $exchange->occurredAt = $log->startedAt() ?? $this->clock->now()->setTimestamp($writtenAt);
        $exchange->endpoint = mb_substr($log->path(), 0, 64);
        $exchange->client = mb_substr($log->headers['user-agent'] ?? '', 0, 64);
        $exchange->requestTail = preg_match('/-([A-Za-z0-9]{1,8})\.log$/', $name, $match) ? $match[1] : '';
        $exchange->provider = str_contains($log->path(), '/messages') ? 'claude' : 'codex';
        $exchange->error = \sprintf('Failas per didelis išskaidyti (%d MB) – jis archyve, užklausos skaitomos tik iš jo.', intdiv($size, 1_048_576));
        $exchange->requestBytes = $size;
        $exchange->archivePath = $archived;
        $entityManager = $this->doctrine->getManager();
        $entityManager->persist($exchange);
        $entityManager->flush();

        return $exchange;
    }

    private function exchangeFor(string $logFile): Exchange
    {
        $exchange = $this->exchanges->findOneBy(['logFile' => $logFile]);
        if (null === $exchange) {
            $exchange = new Exchange();
            $exchange->logFile = $logFile;
        }

        return $exchange;
    }

    /** Database and disk trouble passes; the file is not to blame. */
    private static function isTransient(\Throwable $exception): bool
    {
        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                return false;
            }
            // The connection gone, the server restarting or out of room, a deadlock or lock wait, a disk problem: worth
            // the next pass. Any other database error (a value too long, …) is the file's: quarantine, or it would
            // block the log for good.
            if ($cause instanceof \Doctrine\DBAL\Exception\ConnectionException || $cause instanceof \Doctrine\DBAL\Exception\RetryableException
                || $cause instanceof ArchiveException
                || ($cause instanceof \Doctrine\DBAL\Exception\DriverException && \in_array($cause->getCode(), self::TRANSIENT_MYSQL_CODES, true))) {
                return true;
            }
        }

        return false;
    }

    private function startAttempt(string $name): int
    {
        $marker = $this->logDirectory.'/.ingesting';
        $state = is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;
        $attempts = \is_array($state) && ($state['file'] ?? null) === $name ? (int) $state['attempts'] + 1 : 1;
        @file_put_contents($marker, json_encode(['file' => $name, 'attempts' => $attempts]));

        return $attempts;
    }

    private function endAttempt(): void
    {
        @unlink($this->logDirectory.'/.ingesting');
    }

    private function quarantineDirectory(): string
    {
        return \dirname($this->archiveDirectory).'/quarantine';
    }

    private function fill(Exchange $exchange, string $name, RequestLog $log, int $writtenAt): void
    {
        $exchange->requestTail = preg_match('/-([A-Za-z0-9]{1,8})\.log$/', $name, $match) ? $match[1] : '';
        $exchange->occurredAt = $log->startedAt() ?? $this->clock->now();
        $exchange->occurredMicros = (int) $exchange->occurredAt->format('u');
        $exchange->endpoint = mb_substr($log->path(), 0, 64);
        $exchange->client = mb_substr($log->headers['user-agent'] ?? '', 0, 64);
        $exchange->statusCode = $log->status ?? 0;
        $exchange->durationMs = max(0, ($writtenAt - $exchange->occurredAt->getTimestamp()) * 1000);
        $exchange->requestBytes = \strlen($log->requestBody);
        $exchange->responseBytes = \strlen($log->responseBody);

        $turn = new Turn();
        $request = json_decode($log->requestBody, true);
        $request = \is_array($request) ? $request : [];
        $path = $log->path();
        if (str_contains($path, '/messages')) {
            $exchange->provider = 'claude';
            AnthropicFormat::read($request, $log->responseBody, $turn);
        } elseif (str_contains($path, '/responses')) {
            $exchange->provider = 'codex';
            ResponsesFormat::read($request, $log->responseBody, $turn);
            $turn->sessionId = $log->headers['session_id'] ?? $log->headers['session-id'] ?? $turn->sessionId;
        } else {
            $exchange->provider = str_starts_with((string) ($request['model'] ?? ''), 'claude') ? 'claude' : 'other';
            $turn->model = (string) ($request['model'] ?? '');
        }
        $turn->sessionId = $log->headers['x-claude-code-session-id'] ?? $turn->sessionId;
        if (str_contains($path, 'count_tokens')) {
            // Claude Code counting tokens: no answer, no person's turn.
            $turn->kind = Turn::KIND_SIDE;
        }
        if ('' === $turn->error && ($exchange->statusCode >= 400 || 0 === $exchange->statusCode)) {
            $turn->error = self::errorText($log->responseBody) ?: self::errorText($log->apiErrors());
        }
        $exchange->failed = $exchange->statusCode >= 400 || 0 === $exchange->statusCode || '' !== $turn->error;
        $this->fillTurn($exchange, $turn);
    }

    /** @param array{at: ?\DateTimeImmutable, endedAt: ?\DateTimeImmutable, request: array<string, mixed>, events: list<array<string, mixed>>, disconnect: string, bytes: int} $timelineTurn */
    private function fillWebsocketTurn(Exchange $exchange, string $name, RequestLog $log, array $timelineTurn): void
    {
        // Every turn of the connection shares its request id: linkAccounts takes their usage records in order.
        $exchange->requestTail = preg_match('/-([A-Za-z0-9]{1,8})\.log$/', $name, $match) ? $match[1] : '';
        $exchange->occurredAt = $timelineTurn['at'] ?? $log->startedAt() ?? $this->clock->now();
        $exchange->occurredMicros = (int) $exchange->occurredAt->format('u');
        $exchange->endpoint = mb_substr($log->path().' (ws)', 0, 64);
        $exchange->client = mb_substr($log->headers['user-agent'] ?? '', 0, 64);
        $exchange->provider = 'codex';
        $exchange->durationMs = null !== $timelineTurn['endedAt'] ? max(0, (int) (($timelineTurn['endedAt']->format('U.u') - $exchange->occurredAt->format('U.u')) * 1000)) : 0;
        $exchange->requestBytes = \strlen((string) json_encode($timelineTurn['request']));
        $exchange->responseBytes = $timelineTurn['bytes'];

        $turn = new Turn();
        ResponsesFormat::readEvents($timelineTurn['request'], $timelineTurn['events'], $turn);
        $turn->sessionId = $log->headers['session_id'] ?? $log->headers['session-id'] ?? $turn->sessionId;
        if (false === ($timelineTurn['request']['generate'] ?? null)) {
            // A prewarm: CLIProxyAPI answers it itself, nothing goes to the model.
            $turn->kind = Turn::KIND_SIDE;
        }
        $completed = \in_array($turn->stopReason, ['completed', 'incomplete'], true) || str_starts_with($turn->stopReason, 'incomplete');
        if ('' === $turn->error && !$completed) {
            $turn->error = '' !== $timelineTurn['disconnect'] ? 'Ryšys nutrūko: '.$timelineTurn['disconnect'] : 'Atsakymas nebaigtas';
        }
        $exchange->statusCode = $completed && '' === $turn->error ? 200 : 0;
        $exchange->failed = !$completed || '' !== $turn->error;
        $this->fillTurn($exchange, $turn);
    }

    private function fillTurn(Exchange $exchange, Turn $turn): void
    {
        $exchange->provider = mb_substr($exchange->provider, 0, 32);
        $exchange->model = mb_substr($turn->model, 0, 128);
        $exchange->reasoningEffort = mb_substr($turn->effort, 0, 32);
        $exchange->sessionId = mb_substr($turn->sessionId, 0, 64);
        $exchange->stream = $turn->stream;
        // A kind the usage record told (subagent, compaction, fork) outlives a reparse.
        if (null === $exchange->usageRecordId || self::NOT_LINKED === $exchange->usageRecordId || Turn::KIND_MAIN === $exchange->kind || Turn::KIND_SIDE === $turn->kind) {
            $exchange->kind = $turn->kind;
        }
        $exchange->error = mb_substr($turn->error, 0, 8192);
        $exchange->stopReason = mb_substr($turn->stopReason, 0, 64);
        $exchange->inputTokens = $turn->inputTokens;
        $exchange->outputTokens = $turn->outputTokens;
        $exchange->cacheReadTokens = $turn->cacheReadTokens;
        $exchange->cacheCreationTokens = $turn->cacheCreationTokens;
        $exchange->reasoningTokens = $turn->reasoningTokens;
        $exchange->promptKind = $turn->promptKind;
        $exchange->prompt = self::cut($turn->prompt, self::MAX_PROMPT);
        $exchange->thinking = self::cut($turn->thinking, self::MAX_TEXT);
        $exchange->hasThinking = '' !== $turn->thinking;
        $exchange->answer = self::cut($turn->text, self::MAX_TEXT);
        $exchange->messageCount = $turn->messageCount;
        $exchange->systemChars = $turn->systemChars;
        $exchange->toolsDeclared = $turn->toolsDeclared;
        // Taken again (a retry, a reparse): calls already known keep their result, which came from a later request.
        $known = [];
        foreach ($exchange->getToolCalls() as $existing) {
            $known[$existing->callId] = $existing;
        }
        $calls = [];
        foreach ($turn->toolCalls as $call) {
            $callId = mb_substr($call['callId'], 0, 128);
            $calls[] = $known[$callId] ?? new ToolCall($exchange, $callId, mb_substr($call['name'], 0, 128), self::cut($call['input'], self::MAX_TOOL_INPUT));
        }
        $exchange->replaceToolCalls($calls);
        $exchange->toolErrorCount = \count(array_filter($turn->toolResults, static fn (array $result) => true === $result['isError']));
        $this->answerToolCalls($turn->toolResults, $exchange->occurredAt);
    }

    /** A file that could not be taken in: kept as it is in quarantine/, with the error in a .error file beside it. */
    private function quarantine(string $path, \Throwable $exception): void
    {
        $directory = $this->quarantineDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new ArchiveException('Cannot create the quarantine '.$directory, 0, $exception);
        }
        $target = $directory.'/'.basename($path);
        if (!@rename($path, $target)) {
            throw new ArchiveException('Cannot move '.basename($path).' to the quarantine', 0, $exception);
        }
        file_put_contents($target.'.error', \sprintf("%s\nmtime: %d\n%s: %s\n", $this->clock->now()->format(\DATE_ATOM), (int) filemtime($target), $exception::class, $exception->getMessage()));
    }

    /** @return list<string> files waiting in quarantine (Įspėjimai shows them) */
    public function quarantined(): array
    {
        return array_map('basename', glob($this->quarantineDirectory().'/*.log') ?: []);
    }

    /** @param list<array{callId: string, isError: ?bool, output: string}> $results */
    private function answerToolCalls(array $results, \DateTimeImmutable $at): void
    {
        $byId = [];
        foreach ($results as $result) {
            if ('' !== $result['callId']) {
                $byId[$result['callId']] = $result;
            }
        }
        foreach ($this->toolCalls->openByCallIds(array_keys($byId)) as $call) {
            $result = $byId[$call->callId];
            $call->answer($result['isError'], self::cut($result['output'], self::MAX_TOOL_OUTPUT), $at);
        }
    }

    /**
     * Account, session, kind and CLIProxyAPI's own timing of exchanges whose usage record arrived (the usage queue and
     * the logs are separate). A request log finds its record by request id; a WebSocket turn by session and time.
     */
    /** @param ?\DateTimeImmutable $since how far back (the collector: a day; a reparse: from its first day) */
    public function linkAccounts(?\DateTimeImmutable $since = null): void
    {
        $entityManager = $this->doctrine->getManager();
        $giveUp = $this->clock->now()->modify('-3 hours');
        $since ??= $this->clock->now()->modify('-1 day');
        $afterId = 0;
        // In pages by id, so a long range (a reparse) is gone through whole.
        while ([] !== ($page = $this->exchanges->withoutAccount($since, $afterId))) {
            foreach ($page as $exchange) {
                $afterId = (int) $exchange->getId();
                $this->link($exchange, $giveUp);
                // Each link is visible to the next query: two turns never take the same record.
                $entityManager->flush();
            }
            $entityManager->clear();
        }
    }

    private function link(Exchange $exchange, \DateTimeImmutable $giveUp): void
    {
        $websocket = str_ends_with($exchange->endpoint, '(ws)');
        // A prewarm or a token count has no usage record of its own: never linked, never in the queue again.
        $neverLinked = Turn::KIND_SIDE === $exchange->kind && ($websocket || str_contains($exchange->endpoint, 'count_tokens'));
        $usage = $neverLinked ? null
            : ($websocket
                ? $this->usage->forWebsocketTurn($exchange->requestTail, $exchange->sessionId, self::startOf($exchange), $this->nextTurnAt($exchange), $exchange->model)
                : $this->usage->forRequest($exchange->requestTail, $exchange->occurredAt));
        if (null === $usage) {
            // Three hours without a record: it is not coming (usage off then, a record lost upstream).
            if ($neverLinked || $exchange->occurredAt < $giveUp) {
                $exchange->usageRecordId = self::NOT_LINKED;
            }

            return;
        }
        $exchange->usageRecordId = $usage->getId();
        $this->enrich($exchange, $usage);
    }

    /** When the same WebSocket connection's next turn started: this turn's record was written before it. */
    private function nextTurnAt(Exchange $exchange): ?\DateTimeImmutable
    {
        if (str_starts_with($exchange->logFile, self::LIVE)) {
            return $this->nextLiveTurnAt($exchange);
        }
        if (!preg_match('/^(.*)#(\d+)$/', $exchange->logFile, $match)) {
            return null;
        }

        $next = $this->exchanges->findOneBy(['logFile' => $match[1].'#'.((int) $match[2] + 1)]);

        return null === $next ? null : self::startOf($next);
    }

    /**
     * Turns of Codex WebSocket connections still open. CLIProxyAPI writes a connection's log file only when it closes
     * (a client may hold it for hours); meanwhile each turn is a part file in logs/request-log-parts-websocket-
     * timeline-*. A part whose answer ended (or that is idle for ten minutes) becomes an exchange named "live:<dir>/
     * <part>", read once. The connection's file, when it comes, brings the same turns with its headers and request id:
     * the live ones are then marked superseded. CLIProxyAPI's files are only read.
     *
     * @return int turns taken in
     */
    public function collectLiveTurns(): int
    {
        $taken = 0;
        $now = $this->clock->now()->getTimestamp();
        $entityManager = $this->doctrine->getManager();
        foreach (glob($this->logDirectory.'/request-log-parts-websocket-timeline-*/*.tmp') ?: [] as $part) {
            $modified = (int) filemtime($part);
            $key = self::LIVE.basename(\dirname($part)).'/'.basename($part);
            if ($modified > $now - self::SETTLE_SECONDS || null !== $this->exchanges->findOneBy(['logFile' => $key])) {
                continue;
            }
            $text = (string) file_get_contents($part, false, null, 0, self::MAX_PARSE_BYTES);
            if ($modified > $now - 600 && !preg_match('/"type":\s*"response\.(completed|done|failed|incomplete)"/', $text)) {
                continue;
            }
            foreach (WebsocketTimeline::turns($text) as $turn) {
                $exchange = $this->exchangeFor(mb_substr($key, 0, 190));
                $at = $turn['at'] ?? $this->clock->now();
                $log = new RequestLog(['URL' => '/v1/responses', 'Timestamp' => $at->format('Y-m-d\TH:i:s.uP')], [], '', [], null, [], '');
                $this->fillWebsocketTurn($exchange, $key, $log, $turn);
                // No headers in a part: CLIProxyAPI's session is the request's prompt_cache_key.
                $exchange->sessionId = mb_substr((string) ($turn['request']['prompt_cache_key'] ?? $exchange->sessionId), 0, 64);
                $exchange->archivePath = '';
                // The connection closed and its file came in first: that turn is the one to keep.
                if (0 < (int) $entityManager->createQuery('SELECT COUNT(e.id) FROM App\Entity\Exchange e WHERE e.logFile NOT LIKE :live AND e.occurredAt = :at AND e.occurredMicros = :micros')
                    ->setParameter('live', self::LIVE.'%')->setParameter('at', $exchange->occurredAt, 'utc_datetime_immutable')->setParameter('micros', $exchange->occurredMicros)
                    ->getSingleScalarResult()) {
                    $exchange->kind = Exchange::KIND_SUPERSEDED;
                }
                $entityManager->persist($exchange);
                $entityManager->flush();
                ++$taken;
                break;
            }
        }
        $entityManager->clear();

        return $taken;
    }

    /** A turn of a closed connection replaces the live one it was read as. */
    private function supersedeLive(Exchange $turn): void
    {
        $this->doctrine->getManager()->createQuery("UPDATE App\Entity\Exchange e SET e.kind = 'superseded' WHERE e.logFile LIKE :live AND e.occurredAt = :at AND e.occurredMicros = :micros")
            ->setParameter('live', self::LIVE.'%')
            ->setParameter('at', $turn->occurredAt, 'utc_datetime_immutable')
            ->setParameter('micros', $turn->occurredMicros)
            ->execute();
    }

    /** The next turn of a live connection: the earliest later start among its part files (each begins with it). */
    private function nextLiveTurnAt(Exchange $exchange): ?\DateTimeImmutable
    {
        $directory = $this->logDirectory.'/'.\dirname(substr($exchange->logFile, \strlen(self::LIVE)));
        $start = self::startOf($exchange);
        $next = null;
        foreach (glob($directory.'/*.tmp') ?: [] as $part) {
            $handle = @fopen($part, 'rb');
            $line = false === $handle ? false : fgets($handle);
            if (false !== $handle) {
                fclose($handle);
            }
            if (false === $line || !preg_match('/^Timestamp: (\S+)/', $line, $match)) {
                continue;
            }
            try {
                $at = (new \DateTimeImmutable($match[1]))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                continue;
            }
            if ($at > $start && (null === $next || $at < $next)) {
                $next = $at;
            }
        }

        return $next;
    }

    /** The exchange's start to the microsecond. */
    private static function startOf(Exchange $exchange): \DateTimeImmutable
    {
        return $exchange->occurredAt->setTime((int) $exchange->occurredAt->format('H'), (int) $exchange->occurredAt->format('i'), (int) $exchange->occurredAt->format('s'), $exchange->occurredMicros);
    }

    /** What the usage record adds to an exchange: account, session, kind, CLIProxyAPI's duration, tokens if none. */
    private function enrich(Exchange $exchange, UsageRecord $usage): void
    {
        $websocket = str_ends_with($exchange->endpoint, '(ws)');
        $exchange->authIndex = $usage->authIndex;
        $exchange->account = $usage->account;
        if ('' === $exchange->sessionId) {
            $exchange->sessionId = $usage->sessionId;
        }
        // CLIProxyAPI's reading of the effort, when the log's request did not say it.
        if ('' === $exchange->reasoningEffort) {
            $exchange->reasoningEffort = $usage->reasoningEffort;
        }
        if (Turn::KIND_SIDE !== $exchange->kind) {
            $exchange->kind = match (true) {
                $usage->isCompaction || 'compaction' === $usage->nodeKind => Turn::KIND_COMPACTION,
                'fork' === $usage->nodeKind => Turn::KIND_FORK,
                '' !== $usage->parentSessionId => Turn::KIND_SUBAGENT,
                default => $exchange->kind,
            };
        }
        // CLIProxyAPI's own measure beats the log file's write time (a WebSocket turn's timeline is exact already).
        if ($usage->latencyMs > 0 && !$websocket) {
            $exchange->durationMs = $usage->latencyMs;
        }
        // The record is the turn's own (exact windows), so a turn cut off before its answer reached the client gets
        // the tokens upstream counted.
        if (0 === $exchange->inputTokens + $exchange->outputTokens) {
            $exchange->inputTokens = $usage->inputTokens;
            $exchange->outputTokens = $usage->outputTokens;
            $exchange->cacheReadTokens = $usage->cacheReadTokens;
            $exchange->cacheCreationTokens = $usage->cacheCreationTokens;
            $exchange->reasoningTokens = $usage->reasoningTokens;
        }
    }

    public function archiveFile(Exchange $exchange): ?string
    {
        if ('' === $exchange->archivePath || str_contains($exchange->archivePath, '..')) {
            return null;
        }
        $path = $this->archiveDirectory.'/'.$exchange->archivePath;

        return is_file($path) ? $path : null;
    }

    /**
     * The compressed copy: written to a temporary file, synced, read back and compared with the original (length and
     * hash) before it is renamed into place. A full disk or a crash never leaves a short copy under the final name, and
     * the original is removed only after this. Taking the same file again writes the same name.
     */
    private function archive(string $name, string $text, \DateTimeImmutable $at): string
    {
        [$relative, $path, $temporary] = $this->archivePaths($name, $at);
        $compressed = gzencode($text, 6);
        $handle = @fopen($temporary, 'wb');
        if (false === $compressed || false === $handle || \strlen($compressed) !== fwrite($handle, $compressed) || !fflush($handle) || !fsync($handle)) {
            if (false !== $handle) {
                fclose($handle);
            }
            @unlink($temporary);
            throw new ArchiveException('Cannot write the archive copy '.$temporary);
        }
        fclose($handle);
        $this->verifyAndPlace($temporary, $path, \strlen($text), sha1($text));

        return $relative;
    }

    /** The same, read and compressed in pieces: for files too large to hold in memory. */
    private function archiveStream(string $name, string $source, \DateTimeImmutable $at): string
    {
        [$relative, $path, $temporary] = $this->archivePaths($name, $at);
        $in = fopen($source, 'rb');
        $out = gzopen($temporary, 'wb6');
        if (false === $in || false === $out) {
            throw new ArchiveException('Cannot stream '.$name.' into the archive');
        }
        $hash = hash_init('sha1');
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1_048_576);
            // A full disk answers 0 (not false): every chunk must go in whole.
            if (false === $chunk || ('' !== $chunk && \strlen($chunk) !== gzwrite($out, $chunk))) {
                fclose($in);
                gzclose($out);
                @unlink($temporary);
                throw new ArchiveException('Cannot write the archive copy '.$temporary);
            }
            hash_update($hash, $chunk);
            $written += \strlen($chunk);
        }
        fclose($in);
        // gzclose() says true even when its last write failed: the read-back below is what tells.
        gzclose($out);
        $this->sync($temporary);
        $this->verifyAndPlace($temporary, $path, $written, hash_final($hash));

        return $relative;
    }

    /** @return array{0: string, 1: string, 2: string} relative path, final path, temporary path */
    private function archivePaths(string $name, \DateTimeImmutable $at): array
    {
        $relative = $at->format('Y-m-d').'/'.$name.'.gz';
        $path = $this->archiveDirectory.'/'.$relative;
        if (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0o750, true) && !is_dir(\dirname($path))) {
            throw new ArchiveException('Cannot create the archive directory '.\dirname($path));
        }

        return [$relative, $path, $path.'.tmp'];
    }

    /** Reads the compressed copy back whole; only an identical one takes the final name. */
    private function verifyAndPlace(string $temporary, string $path, int $length, string $sha1): void
    {
        $hash = hash_init('sha1');
        $read = 0;
        try {
            self::gunzip($temporary, $length, static function (string $chunk) use ($hash, &$read) {
                hash_update($hash, $chunk);
                $read += \strlen($chunk);
            });
        } catch (ArchiveException $exception) {
            @unlink($temporary);
            throw $exception;
        }
        if ($read !== $length || !hash_equals($sha1, hash_final($hash))) {
            @unlink($temporary);
            throw new ArchiveException(\sprintf('The archive copy %s does not read back whole (%d of %d bytes)', basename($path), $read, $length));
        }
        chmod($temporary, 0o600);
        if (!rename($temporary, $path)) {
            throw new ArchiveException('Cannot move the archive copy into place: '.$path);
        }
    }

    /**
     * Expands a gzip file strictly, in pieces handed to $each: a stream cut short, a damaged one or a wrong checksum
     * (zlib checks the trailer's CRC-32 and length) is an error, never a shorter text.
     */
    private static function gunzip(string $path, int $maxBytes, callable $each): void
    {
        $in = is_file($path) ? @fopen($path, 'rb') : false;
        $inflate = inflate_init(\ZLIB_ENCODING_GZIP);
        if (false === $in || false === $inflate) {
            throw new ArchiveException('Cannot read the archive copy '.basename($path));
        }
        $expanded = 0;
        try {
            while (\ZLIB_STREAM_END !== inflate_get_status($inflate) && !feof($in)) {
                // Small compressed pieces: one of them expands at most ~1000 times.
                $compressed = fread($in, 65_536);
                $chunk = false === $compressed ? false : @inflate_add($inflate, $compressed, \ZLIB_SYNC_FLUSH);
                if (false === $chunk) {
                    throw new ArchiveException('The archive copy '.basename($path).' is damaged');
                }
                $expanded += \strlen($chunk);
                if ($expanded > $maxBytes) {
                    throw new ArchiveException(\sprintf('The archive copy %s expands beyond %d MB', basename($path), intdiv($maxBytes, 1_048_576) ?: 1));
                }
                $each($chunk);
            }
            if (\ZLIB_STREAM_END !== inflate_get_status($inflate)) {
                throw new ArchiveException('The archive copy '.basename($path).' is cut short');
            }
        } finally {
            fclose($in);
        }
    }

    private function sync(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle || !fsync($handle)) {
            throw new ArchiveException('Cannot sync '.$path);
        }
        fclose($handle);
    }

    private static function errorText(string $body): string
    {
        $decoded = json_decode(trim($body), true);
        if (\is_array($decoded)) {
            return (string) ($decoded['error']['message'] ?? $decoded['message'] ?? (\is_string($decoded['error'] ?? null) ? $decoded['error'] : ''));
        }

        return mb_substr(trim($body), 0, 500);
    }

    private static function cut(string $text, int $bytes): string
    {
        if (\strlen($text) <= $bytes) {
            return $text;
        }

        return mb_strcut($text, 0, $bytes).'… [sutrumpinta, pilnas – archyve]';
    }
}
