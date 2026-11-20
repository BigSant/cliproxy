<?php

namespace App\Tests\Unit;

use App\CliProxy\Account;
use App\Entity\QuotaSnapshot;
use App\Quota\PoolForecast;
use App\Quota\QuotaWindow;
use App\Tests\Support\FakeCliProxy;
use PHPUnit\Framework\TestCase;

class PoolForecastTest extends TestCase
{
    private const int NOW = 1_794_220_000;

    public function testThePoolBurnsFromTheSoonestResetAndRefillsOnResets(): void
    {
        $a = Account::fromArray(FakeCliProxy::credential('claude', 'a@x'));
        $b = Account::fromArray(FakeCliProxy::credential('claude', 'b@x'));
        // a: 40 % → 46 % over the last three hours (2 %/h), resets in 10 h; b: steady at 20 %, resets in 4 days.
        $history = [
            'idx-a@x' => [self::snap('idx-a@x', -3 * 3600, 40, 10 * 3600), self::snap('idx-a@x', 0, 46, 10 * 3600)],
            'idx-b@x' => [self::snap('idx-b@x', -3 * 3600, 20, 4 * 86400), self::snap('idx-b@x', 0, 20, 4 * 86400)],
        ];

        $forecast = PoolForecast::build([$a, $b], $history, QuotaWindow::WEEK, self::NOW, self::NOW - 3 * 86400, self::NOW + 11 * 86400, 3);

        self::assertSame(200.0, $forecast->poolMax);
        self::assertEqualsWithDelta(134.0, $forecast->poolNow, 0.01);
        self::assertEqualsWithDelta(2.0, $forecast->burn, 0.01);
        self::assertSame(self::NOW + 10 * 3600, $forecast->nextReset['t']);
        // a has used 46 %, plus ten hours at 2 %/h before it resets.
        self::assertEqualsWithDelta(66.0, $forecast->nextReset['gain'], 1.0);
        self::assertNotEmpty($forecast->resets);
        // 2 %/h is 336 % a week, more than two accounts give back: the pool runs dry, but only after a's reset.
        self::assertGreaterThan(self::NOW + 10 * 3600, $forecast->poolEmptyAt);
        self::assertNotEmpty($forecast->recorded);
        self::assertSame(self::NOW, end($forecast->recorded)['t']);
    }

    public function testAFastBurnEmptiesThePool(): void
    {
        $a = Account::fromArray(FakeCliProxy::credential('codex', 'a@x'));
        $history = ['idx-a@x' => [self::snap('idx-a@x', -3600, 50, 6 * 86400), self::snap('idx-a@x', 0, 80, 6 * 86400)]];

        $forecast = PoolForecast::build([$a], $history, QuotaWindow::WEEK, self::NOW, self::NOW - 86400, self::NOW + 6 * 86400, 1);

        self::assertEqualsWithDelta(30.0, $forecast->burn, 0.01);
        self::assertNotNull($forecast->poolEmptyAt);
        self::assertEqualsWithDelta(self::NOW + 2400, $forecast->poolEmptyAt, 900);
        self::assertSame('a@x', $forecast->firstEmpty['label']);
    }

    public function testAccountsWithoutThisWindowAreLeftOut(): void
    {
        $a = Account::fromArray(FakeCliProxy::credential('claude', 'a@x'));

        $forecast = PoolForecast::build([$a], [], QuotaWindow::WEEK_FABLE, self::NOW, self::NOW - 86400, self::NOW + 86400, 3);

        self::assertSame(1, $forecast->unknown);
        self::assertSame(0.0, $forecast->poolMax);
    }

    private static function snap(string $authIndex, int $offset, float $weekUsed, int $resetIn): QuotaSnapshot
    {
        return new QuotaSnapshot($authIndex, $authIndex, 'claude', new \DateTimeImmutable('@'.(self::NOW + $offset)), [
            new QuotaWindow(QuotaWindow::WEEK, '7 d.', $weekUsed, new \DateTimeImmutable('@'.(self::NOW + $resetIn))),
        ], 'live');
    }

    public function testEachProviderCardShowsAWindowItHas(): void
    {
        self::assertSame(QuotaWindow::AG_GEMINI, \App\Controller\QuotaController::cardWindow('antigravity', QuotaWindow::WEEK));
        self::assertSame(QuotaWindow::AG_FLASH, \App\Controller\QuotaController::cardWindow('antigravity', QuotaWindow::AG_FLASH));
        self::assertSame(QuotaWindow::WEEK, \App\Controller\QuotaController::cardWindow('claude', QuotaWindow::AG_FLASH));
        self::assertSame(QuotaWindow::WEEK, \App\Controller\QuotaController::cardWindow('codex', QuotaWindow::WEEK_FABLE));
        self::assertSame(QuotaWindow::WEEK_FABLE, \App\Controller\QuotaController::cardWindow('claude', QuotaWindow::WEEK_FABLE));
        self::assertSame(18000, PoolForecast::WINDOWS[QuotaWindow::AG_GEMINI]);
    }
}
