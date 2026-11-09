<?php

namespace App\Tests\Functional;

use App\Entity\QuotaSnapshot;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Settings\Settings;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use App\Usage\Collector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CollectorTest extends KernelTestCase
{
    use Database;

    private FakeCliProxy $cliProxy;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cliProxy = self::getContainer()->get(FakeCliProxy::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::resetDatabase($this->entityManager);
    }

    public function testUsageMovesFromTheQueueIntoTheDatabaseInBatches(): void
    {
        $this->cliProxy->queue = array_fill(0, 501, ['timestamp' => '2026-11-09T12:00:00Z', 'provider' => 'claude', 'tokens' => ['total_tokens' => 5]]);

        self::assertSame(501, self::getContainer()->get(Collector::class)->collectUsage());
        self::assertSame([], $this->cliProxy->queue);
        self::assertSame(501, $this->entityManager->getRepository(UsageRecord::class)->count([]));
    }

    public function testLimitsAreReadPerAccountAndPrioritiesFollowTheWeeklyReset(): void
    {
        $this->cliProxy->credentials = [
            FakeCliProxy::credential('claude', 'a@x', ['priority' => 5]),
            FakeCliProxy::credential('codex', 'b@x', ['id_token' => ['chatgpt_account_id' => 'acc-b']]),
        ];
        $this->cliProxy->providerAnswers = [
            QuotaFetcher::CLAUDE_USAGE_URL => ['status_code' => 200, 'body' => json_encode([
                'five_hour' => ['utilization' => 20, 'resets_at' => '2026-11-09T15:00:00Z'],
                'seven_day' => ['utilization' => 50, 'resets_at' => '2026-11-11T08:00:00Z'],
            ])],
            QuotaFetcher::CODEX_USAGE_URL => ['status_code' => 200, 'body' => json_encode([
                'plan_type' => 'pro',
                'rate_limit' => ['primary_window' => ['used_percent' => 10], 'secondary_window' => ['used_percent' => 40]],
                'rate_limit_reset_credits' => ['available_count' => 2],
            ])],
        ];
        self::getContainer()->get(Settings::class)->set(Settings::AUTO_PRIORITY, true);

        self::assertSame(2, self::getContainer()->get(Collector::class)->refreshQuotas());

        $snapshots = $this->entityManager->getRepository(QuotaSnapshot::class)->findBy([], ['provider' => 'ASC']);
        self::assertSame(['claude', 'codex'], array_map(fn ($s) => $s->provider, $snapshots));
        self::assertSame(50.0, $snapshots[0]->window('7d')?->used);
        self::assertSame('pro', $snapshots[1]->plan);
        self::assertSame(2, $snapshots[1]->bankedResets);
        $codexCall = array_values(array_filter($this->cliProxy->calls, fn ($c) => ($c['body']['url'] ?? '') === QuotaFetcher::CODEX_USAGE_URL))[0];
        self::assertSame('acc-b', $codexCall['body']['header']['Chatgpt-Account-Id']);
        // Each provider's only usable account gets priority 1: the Claude one had 5, the Codex one 0.
        self::assertSame(1, $this->cliProxy->credentials[0]['priority']);
        self::assertSame(1, $this->cliProxy->credentials[1]['priority']);
    }

    public function testAProviderErrorFallsBackToTheLastHeaders(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'a@x', ['quota' => ['signals' => [
            'Anthropic-Ratelimit-Unified-5h-Utilization' => '0.5',
        ]]])];

        self::getContainer()->get(Collector::class)->refreshQuotas();

        $snapshot = $this->entityManager->getRepository(QuotaSnapshot::class)->findOneBy([]);
        self::assertSame('headers', $snapshot->source);
        self::assertSame('Provider answered 500', $snapshot->error);
        self::assertSame(50.0, $snapshot->window('5h')?->used);
    }
}
