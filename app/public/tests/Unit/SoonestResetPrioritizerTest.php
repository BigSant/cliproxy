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
        ], SoonestResetPrioritizer::priorities($accounts, $snapshots));
    }

    private static function snapshot(string $authIndex, float $weekUsed, string $weekReset): QuotaSnapshot
    {
        return new QuotaSnapshot($authIndex, $authIndex, 'claude', new \DateTimeImmutable(), [
            new QuotaWindow(QuotaWindow::FIVE_HOUR, '5 val.', 0, null),
            new QuotaWindow(QuotaWindow::WEEK, '7 d.', $weekUsed, new \DateTimeImmutable($weekReset)),
        ], 'live');
    }
}
