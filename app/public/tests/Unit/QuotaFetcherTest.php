<?php

namespace App\Tests\Unit;

use App\Quota\QuotaFetcher;
use App\Quota\QuotaWindow;
use PHPUnit\Framework\TestCase;

class QuotaFetcherTest extends TestCase
{
    public function testClaudeUsageGivesTheFiveHourWeeklyAndFableWindows(): void
    {
        $windows = QuotaFetcher::claudeWindows([
            'five_hour' => ['utilization' => 42.0, 'resets_at' => '2026-11-09T15:00:00+00:00'],
            'seven_day' => ['utilization' => 71, 'resets_at' => '2026-11-12T08:00:00+00:00'],
            'iguana_necktie' => ['utilization' => 12.5, 'resets_at' => '2026-11-12T08:00:00+00:00'],
            'seven_day_opus' => null,
        ]);

        self::assertSame([QuotaWindow::FIVE_HOUR, QuotaWindow::WEEK, QuotaWindow::WEEK_FABLE], array_map(fn ($w) => $w->key, $windows));
        self::assertSame(71.0, $windows[1]->used);
        self::assertSame('2026-11-12T08:00:00+00:00', $windows[1]->resetsAt?->format(\DATE_ATOM));
    }

    public function testCodexUsageReadsTheWindowLengthAndUnixResets(): void
    {
        $windows = QuotaFetcher::codexWindows(['rate_limit' => [
            // Reversed on purpose: the length decides which window it is.
            'primary_window' => ['used_percent' => 30, 'limit_window_seconds' => 604800, 'reset_at' => 1794300000],
            'secondary_window' => ['used_percent' => 100, 'limit_window_seconds' => 18000, 'reset_at' => 1794240000],
        ]]);

        self::assertSame(QuotaWindow::WEEK, $windows[0]->key);
        self::assertSame(1794300000, $windows[0]->resetsAt?->getTimestamp());
        self::assertSame(QuotaWindow::FIVE_HOUR, $windows[1]->key);
        self::assertTrue($windows[1]->isExhausted());
    }

    public function testCodexUsageWithoutLengthsTakesPrimaryAsTheFiveHourWindow(): void
    {
        $windows = QuotaFetcher::codexWindows(['rate_limit' => [
            'primary_window' => ['used_percent' => 5],
            'secondary_window' => ['used_percent' => 9],
        ]]);

        self::assertSame([QuotaWindow::FIVE_HOUR, QuotaWindow::WEEK], array_map(fn ($w) => $w->key, $windows));
        self::assertNull($windows[0]->resetsAt);
    }

    public function testHeadersTurnAnthropicFractionsIntoPercent(): void
    {
        $windows = QuotaFetcher::fromHeaders([
            'anthropic-ratelimit-unified-5h-utilization' => '0.25',
            'anthropic-ratelimit-unified-5h-reset' => '1794240000',
            'anthropic-ratelimit-unified-7d-utilization' => '0.9',
        ]);

        self::assertSame(25.0, $windows[0]->used);
        self::assertSame(1794240000, $windows[0]->resetsAt?->getTimestamp());
        self::assertSame(90.0, $windows[1]->used);
    }

    public function testHeadersOfCodex(): void
    {
        $windows = QuotaFetcher::fromHeaders(['x-codex-secondary-used-percent' => '64', 'x-codex-secondary-reset-at' => '1794300000']);

        self::assertCount(1, $windows);
        self::assertSame(QuotaWindow::WEEK, $windows[0]->key);
        self::assertSame(64.0, $windows[0]->used);
    }
}
