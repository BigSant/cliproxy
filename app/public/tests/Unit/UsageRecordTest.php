<?php

namespace App\Tests\Unit;

use App\Entity\UsageRecord;
use PHPUnit\Framework\TestCase;

class UsageRecordTest extends TestCase
{
    public function testAQueueRecordKeepsTokensCacheAndOnlyTheKeyEnding(): void
    {
        $record = UsageRecord::fromQueueRecord([
            'timestamp' => '2026-11-09T12:00:00Z', 'provider' => 'anthropic', 'model' => 'claude-fable-5-1',
            'auth_index' => 'idx-1', 'source' => 'me@x', 'session_id' => 'sess-1', 'api_key' => 'secret-key-abcd',
            'endpoint' => '/v1/messages', 'latency_ms' => 1500, 'failed' => false, 'unknown_new_field' => 1,
            'tokens' => ['input_tokens' => 10, 'output_tokens' => 20, 'cache_read_tokens' => 3000, 'cache_creation_tokens' => 400, 'cache_creation_5m_tokens' => 100, 'cache_creation_1h_tokens' => 300, 'total_tokens' => 3430],
        ]);

        self::assertSame('claude', $record->provider);
        self::assertSame('abcd', $record->clientKey);
        self::assertSame(3000, $record->cacheReadTokens);
        self::assertSame(400, $record->cacheCreationTokens);
        self::assertSame([100, 300], [$record->cacheCreation5mTokens, $record->cacheCreation1hTokens]);
        self::assertSame('2026-11-09 12:00:00', $record->occurredAt->format('Y-m-d H:i:s'));
    }

    public function testCodexInputIsCountedWithoutTheCachedTokensAsClaudesIs(): void
    {
        $codex = ['timestamp' => '2026-11-09T12:00:00Z', 'provider' => 'codex', 'tokens' => ['input_tokens' => 5000, 'cached_tokens' => 4000, 'output_tokens' => 60, 'total_tokens' => 5060]];
        self::assertSame([1000, 4000], [UsageRecord::fromQueueRecord($codex)->inputTokens, UsageRecord::fromQueueRecord($codex)->cacheReadTokens]);

        // CLIProxyAPI's own breakdown when it has one.
        $codex['token_breakdown'] = ['schema_version' => 2, 'input' => ['total_tokens' => 5000, 'uncached_tokens' => 990, 'cache_read_tokens' => 4010]];
        self::assertSame(990, UsageRecord::fromQueueRecord($codex)->inputTokens);
        // One CLIProxyAPI could not complete has its parts at 0: worked out instead.
        $codex['token_breakdown'] = ['quality' => 'inconsistent', 'input' => ['uncached_tokens' => 0]];
        self::assertSame(1000, UsageRecord::fromQueueRecord($codex)->inputTokens);

        $claude = ['timestamp' => '2026-11-09T12:00:00Z', 'provider' => 'claude', 'tokens' => ['input_tokens' => 10, 'cache_read_tokens' => 4000]];
        self::assertSame(10, UsageRecord::fromQueueRecord($claude)->inputTokens);
    }

    public function testTheClientKeyAndUnneededHeadersNeverReachStorage(): void
    {
        $record = UsageRecord::sanitize(['api_key' => 'cpa-0123456789abcdef', 'response_headers' => [
            'Set-Cookie' => ['session=secret'], 'Anthropic-Organization-Id' => ['org'], 'Anthropic-Ratelimit-Unified-5h-Utilization' => ['0.2'],
            'X-Codex-Primary-Used-Percent' => ['5'], 'Retry-After' => ['3'],
        ]]);

        self::assertSame('…cdef', $record['api_key']);
        self::assertSame(['Anthropic-Ratelimit-Unified-5h-Utilization', 'X-Codex-Primary-Used-Percent', 'Retry-After'], array_keys($record['response_headers']));
        self::assertSame($record, UsageRecord::sanitize($record), 'sanitizing twice changes nothing (replays keep their key)');
    }

    public function testAnEmptyRecordStillLoads(): void
    {
        $record = UsageRecord::fromQueueRecord([]);

        self::assertSame(0, $record->totalTokens);
        self::assertSame('', $record->model);
    }
}
