<?php

namespace App\Usage;

use App\CliProxy\ManagementClient;
use App\Entity\QuotaSnapshot;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Repository\QuotaSnapshotRepository;
use App\Repository\UsageRecordRepository;
use App\Routing\SoonestResetPrioritizer;
use App\Settings\Settings;
use App\Traffic\TrafficIngester;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * What the hub does in the background (app:collect, every minute from cron): moves usage records out of CLIProxyAPI's
 * queue (through the spool) and its request logs into the database, reads every account's limits when they are older
 * than the refresh interval, and applies soonest-reset-first priorities when they are on. Nothing is ever deleted:
 * the history is for analysis later.
 */
class Collector
{
    private const int BATCH = 500;
    /** A passive limits snapshot from response headers at most this often per account. */
    private const int HEADER_SNAPSHOT_SECONDS = 60;

    private bool $limitsChanged = false;

    public function __construct(
        private readonly ManagementClient $client,
        private readonly QuotaFetcher $quotaFetcher,
        private readonly SoonestResetPrioritizer $prioritizer,
        private readonly Settings $settings,
        private readonly UsageRecordRepository $usage,
        private readonly QuotaSnapshotRepository $snapshots,
        private readonly TrafficIngester $traffic,
        private readonly UsageSpool $spool,
        private readonly ManagerRegistry $doctrine,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Takes the queue in batches: each batch goes to the spool first, then to the database. A failed database write
     * leaves the day pending; the next pass replays it.
     *
     * @return int the usage records stored
     */
    public function collectUsage(): int
    {
        $stored = $this->replayPending();
        do {
            $records = $this->client->takeUsage(self::BATCH);
            if ([] === $records) {
                break;
            }
            $day = $this->spool->append($records, $this->clock->now());
            try {
                $stored += $this->store($records);
            } catch (\Throwable $exception) {
                $this->spool->markPending($day);
                $this->doctrine->resetManager();
                throw $exception;
            }
        } while (\count($records) === self::BATCH);
        if ($this->limitsChanged && $this->settings->autoPriority()) {
            $this->limitsChanged = false;
            $this->prioritizer->apply($this->client->accounts(), $this->snapshots->latest(), $this->clock->now());
        }

        return $stored;
    }

    /** @return int the records of the day the database did not have yet */
    public function replay(string $day): int
    {
        $stored = 0;
        foreach ($this->spool->read($day) as $records) {
            $stored += $this->store($records);
        }

        return $stored;
    }

    /** @return int the request logs taken in */
    public function collectTraffic(): int
    {
        return $this->traffic->ingest();
    }

    public function linkTraffic(): void
    {
        $this->traffic->linkAccounts();
    }

    public function quotasDue(): bool
    {
        if (true === $this->settings->get(Settings::QUOTA_REFRESH_REQUESTED)) {
            return true;
        }
        $last = $this->settings->instant(Settings::QUOTA_REFRESHED_AT);

        return null === $last
            || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $this->settings->quotaRefreshMinutes() * 60 - 5;
    }

    /** @return int the accounts read */
    public function refreshQuotas(): int
    {
        $accounts = $this->client->accounts();
        $entityManager = $this->doctrine->getManager();
        $userAgents = $this->usage->latestUserAgents();
        foreach ($accounts as $account) {
            if ($account->isClaude() || $account->isCodex()) {
                $entityManager->persist($this->quotaFetcher->fetch($account, $userAgents[$account->provider] ?? null));
            }
        }
        $entityManager->flush();
        $this->settings->markNow(Settings::QUOTA_REFRESHED_AT, $this->clock->now());
        $this->settings->set(Settings::QUOTA_REFRESH_REQUESTED, false);
        if ($this->settings->autoPriority()) {
            $this->prioritizer->apply($accounts, $this->snapshots->latest(), $this->clock->now());
        }

        return \count($accounts);
    }

    private function replayPending(): int
    {
        $days = $this->spool->pendingDays();
        if ([] === $days) {
            return 0;
        }
        $stored = 0;
        foreach ($days as $day) {
            $stored += $this->replay($day);
        }
        $this->spool->clearPending();

        return $stored;
    }

    /**
     * Saves the records the database does not have yet. A record that carries the provider's rate-limit headers also
     * gives its account a passive limits snapshot (no extra request to the provider).
     *
     * @param list<array<string, mixed>> $records
     */
    private function store(array $records): int
    {
        $entityManager = $this->doctrine->getManager();
        $rows = [];
        foreach ($records as $record) {
            $row = UsageRecord::fromQueueRecord($record);
            $rows[$row->recordKey] = $row;
        }
        foreach ($this->usage->existingKeys(array_keys($rows)) as $key) {
            unset($rows[$key]);
        }
        $newestWithHeaders = [];
        foreach ($rows as $row) {
            $entityManager->persist($row);
            if ('' !== $row->authIndex && [] !== $row->rateLimitHeaders()
                && (!isset($newestWithHeaders[$row->authIndex]) || $row->occurredAt > $newestWithHeaders[$row->authIndex]->occurredAt)) {
                $newestWithHeaders[$row->authIndex] = $row;
            }
        }
        $latest = [] === $newestWithHeaders ? [] : $this->snapshots->latest();
        foreach ($newestWithHeaders as $authIndex => $row) {
            $previous = $latest[$authIndex] ?? null;
            if (null !== $previous && $row->occurredAt->getTimestamp() < $previous->fetchedAt->getTimestamp() + self::HEADER_SNAPSHOT_SECONDS) {
                continue;
            }
            $windows = [];
            foreach (QuotaFetcher::fromHeaders($row->rateLimitHeaders()) as $window) {
                $windows[$window->key] = $window;
            }
            if ([] === $windows) {
                continue;
            }
            // The headers know the 5-hour and weekly windows; others (Claude's weekly Fable) carry over.
            foreach ($previous?->windows() ?? [] as $window) {
                $windows[$window->key] ??= $window;
            }
            $entityManager->persist(new QuotaSnapshot($authIndex, $row->account, $row->provider, $row->occurredAt,
                array_values($windows), 'headers', $previous?->plan, $previous?->bankedResets));
            $this->limitsChanged = true;
        }
        $entityManager->flush();
        $entityManager->clear();

        return \count($rows);
    }
}
