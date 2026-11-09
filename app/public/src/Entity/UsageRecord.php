<?php

namespace App\Entity;

use App\Repository\UsageRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One upstream attempt of a request the hub proxied, as CLIProxyAPI's usage queue reported it (a retry or a switch to
 * another account is another record with the same request id). CLIProxyAPI keeps no history of its own, so these rows
 * are the hub's only memory of tokens, cache reads and writes, and which account served which session. The whole
 * record is kept in $raw: fields the hub does not read today stay available, and the queue gives each record once.
 */
#[ORM\Entity(repositoryClass: UsageRecordRepository::class)]
#[ORM\Index(name: 'usage_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'usage_account', columns: ['auth_index', 'occurred_at'])]
#[ORM\Index(name: 'usage_session', columns: ['session_id'])]
#[ORM\Index(name: 'usage_request', columns: ['request_tail'])]
#[ORM\Index(name: 'usage_request_id', columns: ['request_id'])]
#[ORM\UniqueConstraint(name: 'usage_record_key', columns: ['record_key'])]
class UsageRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    /** sha1 of the record as CLIProxyAPI sent it: the same record imported twice (spool replay) is one row. */
    #[ORM\Column(length: 40, nullable: true)]
    public ?string $recordKey = null;
    #[ORM\Column(length: 64, options: ['default' => ''])]
    public string $requestId = '';
    #[ORM\Column(options: ['default' => 0])]
    public int $ttftMs = 0;
    #[ORM\Column(length: 190, options: ['default' => ''])]
    public string $userAgent = '';
    #[ORM\Column(length: 32, options: ['default' => ''])]
    public string $reasoningEffort = '';
    /** Set on a subagent's requests: the session that started it. */
    #[ORM\Column(length: 64, options: ['default' => ''])]
    public string $parentSessionId = '';
    /** CLIProxyAPI's request kind: '' (a session's own turn), fork, compaction. */
    #[ORM\Column(length: 32, options: ['default' => ''])]
    public string $nodeKind = '';
    #[ORM\Column(options: ['default' => false])]
    public bool $isCompaction = false;
    /** Another attempt of a request whose previous attempt failed (a retry or a switch to another account). */
    #[ORM\Column(options: ['default' => false])]
    public bool $isRetry = false;
    /** A failed attempt another attempt of the same request followed: not a request of its own, not a failed request.
     * Counting by these (not by the retries) keeps every group – account, model, day – from going negative. */
    #[ORM\Column(options: ['default' => false])]
    public bool $superseded = false;
    /** The microseconds of the timestamp (the column keeps seconds): attempts in one second keep their order. */
    #[ORM\Column(options: ['default' => 0])]
    public int $occurredMicros = 0;
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $raw = null;

    public function __construct(
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 32)]
        public readonly string $provider,
        #[ORM\Column(length: 128)]
        public readonly string $model,
        #[ORM\Column(length: 64)]
        public readonly string $authIndex,
        /** The account's label (e-mail) at the time. */
        #[ORM\Column(length: 190)]
        public readonly string $account,
        #[ORM\Column(length: 64)]
        public readonly string $sessionId,
        /** The last four characters of the client key the request came with. */
        #[ORM\Column(length: 8)]
        public readonly string $clientKey,
        #[ORM\Column(length: 64)]
        public readonly string $endpoint,
        #[ORM\Column]
        public readonly int $inputTokens,
        #[ORM\Column]
        public readonly int $outputTokens,
        #[ORM\Column]
        public readonly int $reasoningTokens,
        #[ORM\Column]
        public readonly int $cacheReadTokens,
        #[ORM\Column]
        public readonly int $cacheCreationTokens,
        #[ORM\Column]
        public readonly int $totalTokens,
        #[ORM\Column]
        public readonly int $latencyMs,
        #[ORM\Column]
        public readonly bool $failed,
        #[ORM\Column]
        public readonly int $statusCode,
        /** The last eight characters of the request id, which the request log's file name ends with too. */
        #[ORM\Column(length: 8, options: ['default' => ''])]
        public readonly string $requestTail = '',
        /** Of cacheCreationTokens: written for 5 minutes and for one hour (Claude). Claude Code through a proxy may
         * fall back to 5 minutes, which rewrites the cache after every longer pause (README, "Prompt cache TTL"). */
        #[ORM\Column(options: ['default' => 0])]
        public readonly int $cacheCreation5mTokens = 0,
        #[ORM\Column(options: ['default' => 0])]
        public readonly int $cacheCreation1hTokens = 0,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Builds the row from a queue record (CLIProxyAPI internal/redisqueue). Unknown or missing fields count as empty,
     * so a newer CLIProxyAPI that adds fields still loads.
     *
     * @param array<string, mixed> $record
     */
    public static function fromQueueRecord(array $record): self
    {
        $tokens = \is_array($record['tokens'] ?? null) ? $record['tokens'] : [];
        $fail = \is_array($record['fail'] ?? null) ? $record['fail'] : [];
        try {
            $occurredAt = new \DateTimeImmutable(\is_string($record['timestamp'] ?? null) ? $record['timestamp'] : 'now');
        } catch (\Exception) {
            $occurredAt = new \DateTimeImmutable();
        }
        $provider = (string) ($record['provider'] ?? '');
        $cacheRead = (int) ($tokens['cache_read_tokens'] ?? $tokens['cached_tokens'] ?? 0);

        $usage = new self(
            occurredAt: $occurredAt,
            provider: substr('anthropic' === $provider ? 'claude' : $provider, 0, 32),
            model: substr((string) ($record['model'] ?? ''), 0, 128),
            authIndex: substr((string) ($record['auth_index'] ?? ''), 0, 64),
            account: mb_substr((string) ($record['source'] ?? ''), 0, 190),
            sessionId: substr((string) ($record['session_id'] ?? ''), 0, 64),
            clientKey: substr((string) ($record['api_key'] ?? ''), -4),
            endpoint: substr((string) ($record['endpoint'] ?? ''), 0, 64),
            inputTokens: self::uncachedInput($record, $tokens, $provider, $cacheRead),
            outputTokens: (int) ($tokens['output_tokens'] ?? 0),
            reasoningTokens: (int) ($tokens['reasoning_tokens'] ?? 0),
            cacheReadTokens: $cacheRead,
            cacheCreationTokens: (int) ($tokens['cache_creation_tokens'] ?? 0),
            totalTokens: (int) ($tokens['total_tokens'] ?? 0),
            latencyMs: (int) ($record['latency_ms'] ?? 0),
            failed: (bool) ($record['failed'] ?? false),
            statusCode: (int) ($fail['status_code'] ?? 0),
            requestTail: substr(trim((string) ($record['request_id'] ?? '')), -8),
            cacheCreation5mTokens: (int) ($tokens['cache_creation_5m_tokens'] ?? 0),
            cacheCreation1hTokens: (int) ($tokens['cache_creation_1h_tokens'] ?? 0),
        );
        $usage->recordKey = self::keyOf($record);
        $usage->requestId = substr(trim((string) ($record['request_id'] ?? '')), 0, 64);
        $usage->ttftMs = (int) ($record['ttft_ms'] ?? 0);
        $usage->userAgent = mb_substr((string) ($record['user_agent'] ?? ''), 0, 190);
        $usage->reasoningEffort = substr((string) ($record['reasoning_effort'] ?? ''), 0, 32);
        $usage->parentSessionId = substr((string) ($record['parent_session_id'] ?? ''), 0, 64);
        $usage->nodeKind = substr((string) ($record['node_kind'] ?? ''), 0, 32);
        $usage->isCompaction = (bool) ($record['is_compaction'] ?? false);
        $usage->raw = $record;
        $usage->occurredMicros = (int) $occurredAt->format('u');

        return $usage;
    }

    /**
     * The input not read from the cache, as Claude counts it: OpenAI's (Codex's) input_tokens include the cached ones,
     * so input, cache reads and cache writes add up the same way for every provider. CLIProxyAPI's token_breakdown
     * says it directly; older records without it are worked out.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $tokens
     */
    private static function uncachedInput(array $record, array $tokens, string $provider, int $cacheRead): int
    {
        $uncached = $record['token_breakdown']['input']['uncached_tokens'] ?? null;
        // Only a breakdown CLIProxyAPI could complete; an inconsistent one leaves the parts at 0.
        if (\is_int($uncached) && $uncached >= 0 && 'complete' === ($record['token_breakdown']['quality'] ?? 'complete')) {
            return $uncached;
        }
        $input = (int) ($tokens['input_tokens'] ?? 0);

        return \in_array($provider, ['codex', 'openai'], true) ? max(0, $input - $cacheRead) : $input;
    }

    /** Response headers worth keeping: limits, retries and request ids; not cookies or organisation ids. */
    private const string KEPT_HEADERS = '/^(anthropic-ratelimit-|x-codex-|retry-after$|request-id$|x-request-id$|openai-request-id$|content-type$)/i';

    /**
     * What may be stored of a queue record: the client key only by its last four characters (CLIProxyAPI sends it
     * whole), and only the response headers in KEPT_HEADERS. The spool and the database both get this, so the key
     * of a record is the same on every path.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public static function sanitize(array $record): array
    {
        if (\is_string($record['api_key'] ?? null) && '' !== $record['api_key']) {
            $record['api_key'] = '…'.substr($record['api_key'], -4);
        }
        if (\is_array($record['response_headers'] ?? null)) {
            $record['response_headers'] = array_filter($record['response_headers'],
                static fn ($name) => 1 === preg_match(self::KEPT_HEADERS, (string) $name), \ARRAY_FILTER_USE_KEY);
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    public static function keyOf(array $record): string
    {
        return sha1(json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /**
     * The provider's rate-limit headers of this attempt's answer (lower-case names), when CLIProxyAPI passed them on.
     *
     * @return array<string, string>
     */
    public function rateLimitHeaders(): array
    {
        $headers = [];
        foreach ((array) ($this->raw['response_headers'] ?? []) as $name => $value) {
            $name = strtolower((string) $name);
            if (str_starts_with($name, 'anthropic-ratelimit-') || str_starts_with($name, 'x-codex-')) {
                $headers[$name] = \is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return $headers;
    }
}
