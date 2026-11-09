<?php

namespace App\Tests\Unit;

use App\CliProxy\Account;
use App\Entity\QuotaSnapshot;
use App\Quota\QuotaWindow;
use App\Routing\SoonestResetPrioritizer;
use App\Tests\Support\FakeCliProxy;
use PHPUnit\Framework\TestCase;

class SoonestResetPrioritizerTest extends TestCase
{
    public function testTheAccountWhoseWeekEndsFirstGoesFirstAndUsedUpOnesLast(): void
    {
        $accounts = [
            Account::fromArray(FakeCliProxy::credential('claude', 'later@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'sooner@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'empty@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'unknown@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'off@x', ['disabled' => true])),
            Account::fromArray(FakeCliProxy::credential('codex', 'only@x')),
        ];
        $snapshots = [
            'idx-later@x' => self::snapshot('idx-later@x', 10, '2026-11-14T08:00:00Z'),
            'idx-sooner@x' => self::snapshot('idx-sooner@x', 60, '2026-11-10T08:00:00Z'),
            'idx-empty@x' => self::snapshot('idx-empty@x', 100, '2026-11-09T20:00:00Z'),
        ];

        self::assertSame([
            'claude-empty@x.json' => 0,
            'claude-sooner@x.json' => 3,
            'claude-later@x.json' => 2,
            'claude-unknown@x.json' => 1,
            'codex-only@x.json' => 1,
        ], SoonestResetPrioritizer::priorities($accounts, $snapshots, new \DateTimeImmutable('2026-11-09T12:00:00Z')));
    }

    public function testANearlyUsedUpFiveHourWindowGoesBelowAccountsWithRoomAndAPassedResetCountsAsFresh(): void
    {
        $now = new \DateTimeImmutable('2026-11-09T12:00:00Z');
        $accounts = [
            Account::fromArray(FakeCliProxy::credential('claude', 'hot@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'cool@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'reset@x')),
            Account::fromArray(FakeCliProxy::credential('claude', 'blocked@x', ['quota_blocked' => true])),
        ];
        $snapshot = static fn (string $id, float $fiveHour, ?string $fiveReset, string $weekReset) => new QuotaSnapshot($id, $id, 'claude', $now, [
            new QuotaWindow(QuotaWindow::FIVE_HOUR, '5 val.', $fiveHour, null !== $fiveReset ? new \DateTimeImmutable($fiveReset) : null),
            new QuotaWindow(QuotaWindow::WEEK, '7 d.', 30, new \DateTimeImmutable($weekReset)),
        ], 'live');
        $snapshots = [
            // Resets soonest, but its 5-hour window is at 95 %.
            'idx-hot@x' => $snapshot('idx-hot@x', 95, '2026-11-09T14:00:00Z', '2026-11-10T00:00:00Z'),
            'idx-cool@x' => $snapshot('idx-cool@x', 10, null, '2026-11-12T00:00:00Z'),
            // Read as used up, but its 5-hour window reset an hour ago.
            'idx-reset@x' => $snapshot('idx-reset@x', 100, '2026-11-09T11:00:00Z', '2026-11-11T00:00:00Z'),
            'idx-blocked@x' => $snapshot('idx-blocked@x', 0, null, '2026-11-09T20:00:00Z'),
        ];

        self::assertSame([
            'claude-blocked@x.json' => 0,
            'claude-reset@x.json' => 3,
            'claude-cool@x.json' => 2,
            'claude-hot@x.json' => 1,
        ], SoonestResetPrioritizer::priorities($accounts, $snapshots, $now));
    }

    private static function snapshot(string $authIndex, float $weekUsed, string $weekReset): QuotaSnapshot
    {
        return new QuotaSnapshot($authIndex, $authIndex, 'claude', new \DateTimeImmutable(), [
            new QuotaWindow(QuotaWindow::FIVE_HOUR, '5 val.', 0, null),
            new QuotaWindow(QuotaWindow::WEEK, '7 d.', $weekUsed, new \DateTimeImmutable($weekReset)),
        ], 'live');
    }
}
