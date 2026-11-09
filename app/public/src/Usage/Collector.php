<?php

namespace App\Usage;

use App\CliProxy\ManagementClient;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Repository\QuotaSnapshotRepository;
use App\Routing\SoonestResetPrioritizer;
use App\Settings\Settings;
use App\Traffic\TrafficIngester;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * What the hub does in the background (app:collect, every minute from cron): moves usage records out of CLIProxyAPI's
 * queue and its request logs into the database, reads every account's limits when they are older than the refresh
 * interval and applies soonest-reset-first priorities when they are on. Nothing is ever deleted: the history is for
 * analysis later.
 */
class Collector
{
    private const int BATCH = 500;

    public function __construct(
        private readonly ManagementClient $client,
        private readonly QuotaFetcher $quotaFetcher,
        private readonly SoonestResetPrioritizer $prioritizer,
        private readonly Settings $settings,
        private readonly QuotaSnapshotRepository $snapshots,
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
}
