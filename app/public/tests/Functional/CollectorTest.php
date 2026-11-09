<?php

namespace App\Tests\Functional;

use App\Entity\QuotaSnapshot;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Settings\Settings;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use App\Usage\Collector;
use App\Usage\UsageSpool;
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
        if (is_dir('/tmp/cliproxy-test/usage')) {
            exec('rm -rf /tmp/cliproxy-test/usage');
        }
    }

    public function testUsageMovesFromTheQueueIntoTheDatabaseInBatches(): void
    {
        $this->cliProxy->queue = array_map(static fn (int $i) => ['timestamp' => '2026-11-09T12:00:00Z', 'request_id' => "req-$i", 'provider' => 'claude', 'tokens' => ['total_tokens' => 5]], range(1, 501));

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

    public function testEveryRecordGoesToTheSpoolFirstAndCanBeReplayedWithoutDuplicates(): void
    {
        $this->cliProxy->queue = [['timestamp' => '2026-11-09T12:00:00Z', 'request_id' => 'req-1', 'provider' => 'claude', 'ttft_ms' => 800, 'node_kind' => 'compaction', 'new_field' => 'kept']];
        $collector = self::getContainer()->get(Collector::class);
        $collector->collectUsage();

        $record = $this->entityManager->getRepository(UsageRecord::class)->findOneBy([]);
        self::assertSame([800, 'compaction', 'kept'], [$record->ttftMs, $record->nodeKind, $record->raw['new_field']]);
        $spool = self::getContainer()->get(UsageSpool::class);
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        self::assertContains($day, $spool->days());

        $this->entityManager->getConnection()->executeStatement('DELETE FROM usage_record');
        self::assertSame(1, $collector->replay($day));
        self::assertSame(0, $collector->replay($day), 'the second replay finds the record stored');

        // A day marked pending (a failed database write) is replayed by the next pass on its own.
        $this->entityManager->getConnection()->executeStatement('DELETE FROM usage_record');
        $spool->markPending($day);
        self::assertSame(1, $collector->collectUsage());
        self::assertSame([], $spool->pendingDays());
    }

    public function testRateLimitHeadersOfAnAnswerGiveAPassiveLimitsSnapshot(): void
    {
        $this->entityManager->persist(new QuotaSnapshot('idx-a@x', 'a@x', 'claude', new \DateTimeImmutable('-10 minutes'), [
            new \App\Quota\QuotaWindow('7d-fable', '7 d. Fable', 12, null),
        ], 'live'));
        $this->entityManager->flush();
        $this->cliProxy->queue = [['timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'request_id' => 'r', 'provider' => 'claude', 'auth_index' => 'idx-a@x', 'source' => 'a@x',
            'response_headers' => ['Anthropic-Ratelimit-Unified-5h-Utilization' => ['0.63'], 'Anthropic-Ratelimit-Unified-7d-Utilization' => ['0.2'], 'Content-Type' => ['text/event-stream']]]];

        self::getContainer()->get(Collector::class)->collectUsage();

        $latest = self::getContainer()->get(\App\Repository\QuotaSnapshotRepository::class)->latest()['idx-a@x'];
        self::assertSame('headers', $latest->source);
        self::assertSame(63.0, $latest->window('5h')?->used);
        self::assertSame(12.0, $latest->window('7d-fable')?->used, 'windows the headers lack carry over');
    }

    public function testTheCronCommandRunsAPassAndLeavesAHeartbeat(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'a@x')];
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:collect'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful($tester->getDisplay());
        $settings = self::getContainer()->get(Settings::class);
        self::assertNotNull($settings->instant(Settings::COLLECTOR_HEARTBEAT));
        self::assertSame('', $settings->get(Settings::COLLECTOR_ERROR));
        self::assertSame('v8.0.61', $settings->get(Settings::CLIPROXY_VERSION));
        $patch = array_values(array_filter($this->cliProxy->calls, fn ($c) => 'PATCH /config' === $c['method'].' '.$c['path']));
        self::assertSame('quota', $patch[0]['body']['routing']['session-affinity-rebind-on'] ?? null, 'the configuration baseline went to CLIProxyAPI');
    }

    public function testADayLeftPendingByACrashIsStoredByTheNextPass(): void
    {
        // A run died after the spool and before the database: the day is marked, the record only in the spool.
        $spool = self::getContainer()->get(UsageSpool::class);
        $day = $spool->append([['timestamp' => '2026-11-09T12:00:00Z', 'request_id' => 'lost', 'provider' => 'claude']], new \DateTimeImmutable());
        $spool->markPending($day);
        self::assertSame('600', substr(sprintf('%o', fileperms("/tmp/cliproxy-test/usage/$day.jsonl")), -3), 'owner only');

        self::assertSame(1, self::getContainer()->get(Collector::class)->collectUsage());
        self::assertSame([], $spool->pendingDays());
    }

    public function testRetriesAreAttemptsNotRequests(): void
    {
        $this->cliProxy->queue = [
            // A failed attempt and its retry on another account: one request, not failed.
            ['timestamp' => '2026-11-09T12:00:00Z', 'request_id' => 'req-r', 'provider' => 'claude', 'failed' => true],
            ['timestamp' => '2026-11-09T12:00:05Z', 'request_id' => 'req-r', 'provider' => 'claude', 'auth_index' => 'b'],
            // A WebSocket connection: two successful turns sharing the request id are two requests.
            ['timestamp' => '2026-11-09T12:01:00Z', 'request_id' => 'conn', 'provider' => 'codex'],
            ['timestamp' => '2026-11-09T12:02:00Z', 'request_id' => 'conn', 'provider' => 'codex'],
            // Failed twice: one failed request.
            ['timestamp' => '2026-11-09T12:03:00Z', 'request_id' => 'req-f', 'provider' => 'claude', 'failed' => true],
            ['timestamp' => '2026-11-09T12:03:02Z', 'request_id' => 'req-f', 'provider' => 'claude', 'failed' => true, 'auth_index' => 'b'],
        ];
        self::getContainer()->get(Collector::class)->collectUsage();

        $totals = self::getContainer()->get(\App\Repository\UsageRecordRepository::class)->totals(new \DateTimeImmutable('2026-11-09T00:00:00Z'));
        self::assertSame([6, 4, 1], [$totals['attempts'], $totals['requests'], $totals['failed']]);
    }

    public function testAFallbackFromOldHeadersDoesNotReplaceANewerReading(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'a@x', ['quota' => [
            'observed_at' => '2026-11-09T11:40:00Z', 'signals' => ['Anthropic-Ratelimit-Unified-5h-Utilization' => '0.6'],
        ]])];
        $this->entityManager->persist(new QuotaSnapshot('idx-a@x', 'a@x', 'claude', new \DateTimeImmutable('2026-11-09T12:00:00Z'),
            [new \App\Quota\QuotaWindow('5h', '5 val.', 95, null)], 'headers'));
        $this->entityManager->flush();

        // The live read fails (no provider answer in the fake): the fallback is older than what is known.
        self::getContainer()->get(Collector::class)->refreshQuotas();

        $latest = self::getContainer()->get(\App\Repository\QuotaSnapshotRepository::class)->latest()['idx-a@x'];
        self::assertSame(95.0, $latest->window('5h')?->used);
        self::assertSame(1, $this->entityManager->getRepository(QuotaSnapshot::class)->count([]));
        self::assertStringContainsString('Provider answered 500', (string) (self::getContainer()->get(Settings::class)->get(Settings::QUOTA_ERRORS)['idx-a@x'] ?? ''));
    }

    public function testNothingOldIsDeleted(): void
    {
        $this->entityManager->persist(UsageRecord::fromQueueRecord(['timestamp' => '2020-01-01T00:00:00Z', 'provider' => 'claude']));
        $this->entityManager->flush();

        $collector = self::getContainer()->get(Collector::class);
        $collector->collectUsage();
        $collector->collectTraffic();
        $collector->refreshQuotas();

        self::assertSame(1, $this->entityManager->getRepository(UsageRecord::class)->count([]));
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
