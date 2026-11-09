<?php

namespace App\Traffic;

use App\Entity\Exchange;
use App\Entity\ToolCall;
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
     * Takes every finished request log, oldest first. A file that cannot be read goes to the quarantine with its
     * error beside it, so it never blocks the files after it; nothing is lost.
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
            try {
                $this->takeFile($path);
                ++$taken;
            } catch (\Throwable $exception) {
                $this->doctrine->resetManager();
                $this->quarantine($path, $exception);
            }
        }

        return $taken;
    }

    /**
     * One log file into the database: a Codex WebSocket connection gives one exchange per turn, any other file one.
     * The compressed copy is in the archive before the database row, and the file leaves the logs directory only
     * after both. Taking a file again (a retry after a failure) replaces its rows instead of adding to them.
     *
     * @return list<Exchange>
     */
    public function takeFile(string $path): array
    {
        $name = basename($path);
        $text = file_get_contents($path);
        if (false === $text) {
            throw new \RuntimeException('Cannot read '.$path);
        }
        $log = RequestLog::parse($text);
        $writtenAt = (int) filemtime($path);
        $archived = $this->archive($name, $text, $log->startedAt() ?? $this->clock->now());
        $entityManager = $this->doctrine->getManager();

        $exchanges = [];
        $timeline = $log->sections['WEBSOCKET TIMELINE'][0] ?? null;
        if (null !== $timeline && null === $log->status) {
            foreach (WebsocketTimeline::turns($timeline) as $index => $turn) {
                $exchange = $this->exchangeFor(mb_substr($name, 0, 180).'#'.($index + 1));
                $this->fillWebsocketTurn($exchange, $log, $turn);
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
            $exchanges[] = $exchange;
        }
        foreach ($exchanges as $exchange) {
            $exchange->archivePath = $archived;
            $entityManager->persist($exchange);
        }
        $entityManager->flush();
        unlink($path);
        $entityManager->clear();

        return $exchanges;
    }

    private function exchangeFor(string $logFile): Exchange
    {
        $exchange = $this->exchanges->findOneBy(['logFile' => $logFile]);
        if (null === $exchange) {
            $exchange = new Exchange();
            $exchange->logFile = $logFile;
        }
        $exchange->clearToolCalls();

        return $exchange;
    }

    private function fill(Exchange $exchange, string $name, RequestLog $log, int $writtenAt): void
    {
        $exchange->requestTail = preg_match('/-([A-Za-z0-9]{1,8})\.log$/', $name, $match) ? $match[1] : '';
        $exchange->occurredAt = $log->startedAt() ?? $this->clock->now();
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
        if ('' === $turn->error && ($exchange->statusCode >= 400 || 0 === $exchange->statusCode)) {
            $turn->error = self::errorText($log->responseBody) ?: self::errorText($log->apiErrors());
        }
        $exchange->failed = $exchange->statusCode >= 400 || 0 === $exchange->statusCode || '' !== $turn->error;
        $this->fillTurn($exchange, $turn);
    }

    /** @param array{at: ?\DateTimeImmutable, endedAt: ?\DateTimeImmutable, request: array<string, mixed>, events: list<array<string, mixed>>, disconnect: string, bytes: int} $timelineTurn */
    private function fillWebsocketTurn(Exchange $exchange, RequestLog $log, array $timelineTurn): void
    {
        // The connection's request id is not any turn's: their usage records are found by session and time.
        $exchange->requestTail = '';
        $exchange->occurredAt = $timelineTurn['at'] ?? $log->startedAt() ?? $this->clock->now();
        $exchange->endpoint = mb_substr($log->path().' (ws)', 0, 64);
        $exchange->client = mb_substr($log->headers['user-agent'] ?? '', 0, 64);
        $exchange->provider = 'codex';
        $exchange->durationMs = null !== $timelineTurn['endedAt'] ? max(0, (int) (($timelineTurn['endedAt']->format('U.u') - $exchange->occurredAt->format('U.u')) * 1000)) : 0;
        $exchange->requestBytes = \strlen((string) json_encode($timelineTurn['request']));
        $exchange->responseBytes = $timelineTurn['bytes'];

        $turn = new Turn();
        ResponsesFormat::readEvents($timelineTurn['request'], $timelineTurn['events'], $turn);
        $turn->sessionId = $log->headers['session_id'] ?? $log->headers['session-id'] ?? $turn->sessionId;
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
        $exchange->sessionId = mb_substr($turn->sessionId, 0, 64);
        $exchange->stream = $turn->stream;
        $exchange->kind = $turn->kind;
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
        foreach ($turn->toolCalls as $call) {
            $exchange->addToolCall(new ToolCall($exchange, mb_substr($call['callId'], 0, 128), mb_substr($call['name'], 0, 128), self::cut($call['input'], self::MAX_TOOL_INPUT)));
        }
        $exchange->toolErrorCount = \count(array_filter($turn->toolResults, static fn (array $result) => true === $result['isError']));
        $this->answerToolCalls($turn->toolResults, $exchange->occurredAt);
    }

    /** A file that could not be taken in: kept as it is in quarantine/, with the error in a .error file beside it. */
    private function quarantine(string $path, \Throwable $exception): void
    {
        $directory = \dirname($this->archiveDirectory).'/quarantine';
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return;
        }
        $target = $directory.'/'.basename($path);
        if (@rename($path, $target)) {
            file_put_contents($target.'.error', \sprintf("%s\n%s: %s\n", $this->clock->now()->format(\DATE_ATOM), $exception::class, $exception->getMessage()));
        }
    }

    /** @return list<string> files waiting in quarantine (Įspėjimai shows them) */
    public function quarantined(): array
    {
        return array_map('basename', glob(\dirname($this->archiveDirectory).'/quarantine/*.log') ?: []);
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
    public function linkAccounts(): void
    {
        $entityManager = $this->doctrine->getManager();
        foreach ($this->exchanges->withoutAccount($this->clock->now()->modify('-1 day')) as $exchange) {
            $usage = '' !== $exchange->requestTail
                ? $this->usage->forRequest($exchange->requestTail, $exchange->occurredAt)
                : $this->usage->forSession($exchange->sessionId, $exchange->occurredAt);
            if (null === $usage) {
                continue;
            }
            $exchange->authIndex = $usage->authIndex;
            $exchange->account = $usage->account;
            if ('' === $exchange->sessionId) {
                $exchange->sessionId = $usage->sessionId;
            }
            if (Turn::KIND_SIDE !== $exchange->kind) {
                $exchange->kind = match (true) {
                    $usage->isCompaction || 'compaction' === $usage->nodeKind => Turn::KIND_COMPACTION,
                    'fork' === $usage->nodeKind => Turn::KIND_FORK,
                    '' !== $usage->parentSessionId => Turn::KIND_SUBAGENT,
                    default => $exchange->kind,
                };
            }
            // CLIProxyAPI's own measure beats the log file's write time.
            if ($usage->latencyMs > 0) {
                $exchange->durationMs = $usage->latencyMs;
            }
            if (0 === $exchange->inputTokens + $exchange->outputTokens) {
                $exchange->inputTokens = $usage->inputTokens;
                $exchange->outputTokens = $usage->outputTokens;
                $exchange->cacheReadTokens = $usage->cacheReadTokens;
                $exchange->cacheCreationTokens = $usage->cacheCreationTokens;
                $exchange->reasoningTokens = $usage->reasoningTokens;
            }
        }
        $entityManager->flush();
        $entityManager->clear();
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
     * The compressed copy, written to a temporary file, checked and renamed into place: a full disk or a crash never
     * leaves a half file under the final name. Taking the same file again writes the same name.
     */
    private function archive(string $name, string $text, \DateTimeImmutable $at): string
    {
        $relative = $at->format('Y-m-d').'/'.$name.'.gz';
        $path = $this->archiveDirectory.'/'.$relative;
        if (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0o750, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create the archive directory '.\dirname($path));
        }
        $compressed = gzencode($text, 6);
        $temporary = $path.'.tmp';
        if (false === $compressed || \strlen($compressed) !== @file_put_contents($temporary, $compressed)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot write the archive copy '.$temporary);
        }
        chmod($temporary, 0o600);
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Cannot move the archive copy into place: '.$path);
        }

        return $relative;
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
