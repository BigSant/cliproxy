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

    public function testCodexHeadersWithAResetAfterAndAWindowLength(): void
    {
        $at = new \DateTimeImmutable('2026-11-09T12:00:00Z');
        $windows = QuotaFetcher::fromHeaders([
            'x-codex-primary-used-percent' => '80', 'x-codex-primary-window-minutes' => '10080', 'x-codex-primary-reset-after-seconds' => '3600',
        ], $at);

        self::assertSame(QuotaWindow::WEEK, $windows[0]->key, 'the length decides, not the name');
        self::assertSame('2026-11-09T13:00:00+00:00', $windows[0]->resetsAt?->format(\DATE_ATOM));
    }

    public function testClaudeHeadersGiveTheFableWindowAndFractionsAboveOne(): void
    {
        $windows = QuotaFetcher::fromHeaders(['anthropic-ratelimit-unified-7d_oi-utilization' => '0.4', 'anthropic-ratelimit-unified-5h-utilization' => '1.05']);

        self::assertSame([[QuotaWindow::FIVE_HOUR, 105.0], [QuotaWindow::WEEK_FABLE, 40.0]], array_map(static fn ($w) => [$w->key, round($w->used, 2)], $windows));
    }

    public function testHeadersOfCodex(): void
    {
        $windows = QuotaFetcher::fromHeaders(['x-codex-secondary-used-percent' => '64', 'x-codex-secondary-reset-at' => '1794300000']);

        self::assertCount(1, $windows);
        self::assertSame(QuotaWindow::WEEK, $windows[0]->key);
        self::assertSame(64.0, $windows[0]->used);
    }

    public function testAntigravityGivesOneWindowPerModelFamilyAsUsedAsItsMostUsedModel(): void
    {
        $windows = QuotaFetcher::antigravityWindows(['models' => [
            'gemini-4-argon-high' => ['quotaInfo' => ['remainingFraction' => 0.8, 'resetTime' => '2026-11-20T23:00:00Z']],
            'gemini-4-neon-xhigh' => ['quotaInfo' => ['remainingFraction' => 0.6, 'resetTime' => '2026-11-20T23:30:00Z']],
            'gemini-3.8-flash-high' => ['quotaInfo' => ['remainingFraction' => 1, 'resetTime' => '2026-11-21T01:00:00Z']],
            // Used up: a reset, no remaining fraction.
            'claude-opus-4-6-thinking' => ['quotaInfo' => ['resetTime' => '2026-11-21T02:00:00Z']],
            'gpt-oss-120b-medium' => ['quotaInfo' => ['remainingFraction' => 0.5, 'resetTime' => '2026-11-21T02:00:00Z']],
            // Pools of their own, or Google's internal models: left out.
            'gemini-3.1-flash-image' => ['quotaInfo' => ['remainingFraction' => 0]],
            'gemini-3.1-flash-lite' => ['quotaInfo' => ['remainingFraction' => 0]],
            'chat_20706' => ['quotaInfo' => ['remainingFraction' => 0]],
            'gemini-3-flash' => ['displayName' => 'no quota info'],
        ]]);

        self::assertSame([
            [QuotaWindow::AG_GEMINI, 40.0, '2026-11-20T23:30:00+00:00'],
            [QuotaWindow::AG_FLASH, 0.0, '2026-11-21T01:00:00+00:00'],
            [QuotaWindow::AG_CLAUDE, 100.0, '2026-11-21T02:00:00+00:00'],
        ], array_map(static fn ($w) => [$w->key, $w->used, $w->resetsAt?->format(\DATE_ATOM)], $windows));
        self::assertTrue($windows[2]->isExhausted());
        self::assertSame([], QuotaFetcher::antigravityWindows(['models' => []]));
    }
}
