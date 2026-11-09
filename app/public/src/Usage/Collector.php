<?php

namespace App\Usage;

use App\CliProxy\ManagementClient;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Repository\QuotaSnapshotRepository;
use App\Repository\UsageRecordRepository;
use App\Repository\ExchangeRepository;
use App\Routing\SoonestResetPrioritizer;
use App\Settings\Settings;
use App\Traffic\TrafficIngester;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * What the hub does in the background (app:collect, every minute from cron): moves usage records out of CLIProxyAPI's
 * queue and its request logs into the database, reads every account's limits when they are older than the refresh
 * interval, applies soonest-reset-first priorities when they are on, and drops old history.
 */
class Collector
{
    private const int BATCH = 500;
    private const string KEEP_USAGE = '-90 days';
    private const string KEEP_QUOTA = '-30 days';

    public function __construct(
        private readonly ManagementClient $client,
        private readonly QuotaFetcher $quotaFetcher,
        private readonly SoonestResetPrioritizer $prioritizer,
        private readonly Settings $settings,
        private readonly UsageRecordRepository $usage,
        private readonly QuotaSnapshotRepository $snapshots,
        private readonly ExchangeRepository $exchanges,
        private readonly TrafficIngester $traffic,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int the usage records stored */
    public function collectUsage(): int
    {
        $stored = 0;
        do {
            $records = $this->client->takeUsage(self::BATCH);
            foreach ($records as $record) {
                $this->entityManager->persist(UsageRecord::fromQueueRecord($record));
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
            $stored += \count($records);
        } while (\count($records) === self::BATCH);

        return $stored;
    }

    /** @return int the request logs taken in */
    public function collectTraffic(): int
    {
        return $this->traffic->ingest();
    }

    public function quotasDue(): bool
    {
        $last = $this->settings->instant(Settings::QUOTA_REFRESHED_AT);

        return null === $last
            || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $this->settings->quotaRefreshMinutes() * 60 - 5;
    }

    /** @return int the accounts read */
    public function refreshQuotas(): int
    {
        $accounts = $this->client->accounts();
        foreach ($accounts as $account) {
            if ($account->isClaude() || $account->isCodex()) {
                $this->entityManager->persist($this->quotaFetcher->fetch($account));
            }
        }
        $this->entityManager->flush();
        $this->settings->markNow(Settings::QUOTA_REFRESHED_AT, $this->clock->now());
        if ($this->settings->autoPriority()) {
            $this->prioritizer->apply($accounts, $this->snapshots->latest());
        }

        return \count($accounts);
    }

    public function pruneIfDue(): void
    {
        $now = $this->clock->now();
        $last = $this->settings->instant(Settings::PRUNED_AT);
        if (null !== $last && $now->getTimestamp() - $last->getTimestamp() < 3600) {
            return;
        }
        $this->usage->deleteOlderThan($now->modify(self::KEEP_USAGE));
        $this->snapshots->deleteOlderThan($now->modify(self::KEEP_QUOTA));
        $this->exchanges->deleteOlderThan($now->modify(self::KEEP_USAGE));
        $this->traffic->pruneArchive($this->settings->archiveDays());
        $this->settings->markNow(Settings::PRUNED_AT, $now);
    }
}
