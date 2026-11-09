<?php

namespace App\Entity;

use App\Repository\UsageRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One request the hub proxied, as CLIProxyAPI's usage queue reported it. CLIProxyAPI keeps no history of its own, so
 * these rows are the hub's only memory of tokens, cache reads and writes, and which account served which session.
 */
#[ORM\Entity(repositoryClass: UsageRecordRepository::class)]
#[ORM\Index(name: 'usage_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'usage_account', columns: ['auth_index', 'occurred_at'])]
#[ORM\Index(name: 'usage_session', columns: ['session_id'])]
#[ORM\Index(name: 'usage_request', columns: ['request_tail'])]
class UsageRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

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
            $occurredAt = new \DateTimeImmutable((string) ($record['timestamp'] ?? 'now'));
        } catch (\Exception) {
            $occurredAt = new \DateTimeImmutable();
        }
        $provider = (string) ($record['provider'] ?? '');

        return new self(
            occurredAt: $occurredAt,
            provider: substr('anthropic' === $provider ? 'claude' : $provider, 0, 32),
            model: substr((string) ($record['model'] ?? ''), 0, 128),
            authIndex: substr((string) ($record['auth_index'] ?? ''), 0, 64),
            account: mb_substr((string) ($record['source'] ?? ''), 0, 190),
            sessionId: substr((string) ($record['session_id'] ?? ''), 0, 64),
            clientKey: substr((string) ($record['api_key'] ?? ''), -4),
            endpoint: substr((string) ($record['endpoint'] ?? ''), 0, 64),
            inputTokens: (int) ($tokens['input_tokens'] ?? 0),
            outputTokens: (int) ($tokens['output_tokens'] ?? 0),
            reasoningTokens: (int) ($tokens['reasoning_tokens'] ?? 0),
            cacheReadTokens: (int) ($tokens['cache_read_tokens'] ?? $tokens['cached_tokens'] ?? 0),
            cacheCreationTokens: (int) ($tokens['cache_creation_tokens'] ?? 0),
            totalTokens: (int) ($tokens['total_tokens'] ?? 0),
            latencyMs: (int) ($record['latency_ms'] ?? 0),
            failed: (bool) ($record['failed'] ?? false),
            statusCode: (int) ($fail['status_code'] ?? 0),
            requestTail: substr(trim((string) ($record['request_id'] ?? '')), -8),
        );
    }
}
