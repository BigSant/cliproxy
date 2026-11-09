<?php

namespace App\Traffic;

use App\Entity\Exchange;
use App\Entity\ToolCall;
use App\Repository\ExchangeRepository;
use App\Repository\ToolCallRepository;
use App\Repository\UsageRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Takes CLIProxyAPI's request logs (one file per request in its logs directory) into the database: every finished
 * file becomes an Exchange with its tool calls, results of earlier calls are matched to them, the file is compressed
 * into the archive and removed from the logs directory. main.log, CLIProxyAPI's own log, is left alone.
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
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRepository $exchanges,
        private readonly ToolCallRepository $toolCalls,
        private readonly UsageRecordRepository $usage,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int the request logs taken in */
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
            $this->takeFile($path);
            ++$taken;
        }
        $this->linkAccounts();

        return $taken;
    }

    public function takeFile(string $path): Exchange
    {
        $name = basename($path);
        $text = (string) file_get_contents($path);
        $exchange = $this->exchanges->findOneBy(['logFile' => mb_substr($name, 0, 190)]) ?? new Exchange();
        $this->fill($exchange, $name, $text, (int) filemtime($path));
        $exchange->archivePath = $this->archive($name, $text, $exchange->occurredAt);
        $this->entityManager->persist($exchange);
        $this->entityManager->flush();
        unlink($path);
        $this->entityManager->clear();

        return $exchange;
    }

    private function fill(Exchange $exchange, string $name, string $text, int $writtenAt): void
    {
        $log = RequestLog::parse($text);
        $exchange->logFile = mb_substr($name, 0, 190);
        $exchange->requestTail = preg_match('/-([A-Za-z0-9]{1,8})\.log$/', $name, $match) ? $match[1] : '';
        $exchange->occurredAt = $log->startedAt() ?? $this->clock->now();
        $exchange->endpoint = mb_substr($log->path(), 0, 64);
        $exchange->client = mb_substr(strtok($log->headers['user-agent'] ?? '', ' ') ?: '', 0, 64);
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

        $exchange->provider = mb_substr($exchange->provider, 0, 32);
        $exchange->model = mb_substr($turn->model, 0, 128);
        $exchange->sessionId = mb_substr($turn->sessionId, 0, 64);
        $exchange->stream = $turn->stream;
        $exchange->failed = $exchange->statusCode >= 400 || 0 === $exchange->statusCode || '' !== $turn->error;
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

    /** Account and session of exchanges whose usage record arrived (the usage queue and the logs are separate). */
    public function linkAccounts(): void
    {
        foreach ($this->exchanges->withoutAccount($this->clock->now()->modify('-2 hours')) as $exchange) {
            $usage = $this->usage->forRequest($exchange->requestTail, $exchange->occurredAt);
            if (null === $usage) {
                continue;
            }
            $exchange->authIndex = $usage->authIndex;
            $exchange->account = $usage->account;
            if ('' === $exchange->sessionId) {
                $exchange->sessionId = $usage->sessionId;
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
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /** Raw logs older than $days leave the archive; their exchanges stay. */
    public function pruneArchive(int $days): void
    {
        if (!is_dir($this->archiveDirectory)) {
            return;
        }
        $oldest = $this->clock->now()->modify("-$days days")->format('Y-m-d');
        foreach (new \DirectoryIterator($this->archiveDirectory) as $day) {
            if ($day->isDir() && !$day->isDot() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day->getFilename()) && $day->getFilename() < $oldest) {
                foreach (new \DirectoryIterator($day->getPathname()) as $file) {
                    if ($file->isFile()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($day->getPathname());
                $this->exchanges->forgetArchiveDay($day->getFilename());
            }
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

    private function archive(string $name, string $text, \DateTimeImmutable $at): string
    {
        $relative = $at->format('Y-m-d').'/'.$name.'.gz';
        $path = $this->archiveDirectory.'/'.$relative;
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o750, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create the archive directory '.\dirname($path));
        }
        file_put_contents($path, gzencode($text, 6));
        chmod($path, 0o600);

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
