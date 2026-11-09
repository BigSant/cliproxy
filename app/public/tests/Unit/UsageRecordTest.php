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

    public function testAnEmptyRecordStillLoads(): void
    {
        $record = UsageRecord::fromQueueRecord([]);

        self::assertSame(0, $record->totalTokens);
        self::assertSame('', $record->model);
    }
}
