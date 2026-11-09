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

    /** What became of the records when collectUsage() failed: in the spool, left in CLIProxyAPI's queue, or lost. */
    public const string IN_SPOOL = 'spool';
    public const string LEFT_IN_QUEUE = 'queue';
    public const string LOST = 'lost';

    private bool $limitsChanged = false;
    public private(set) ?string $usageOutcome = null;
    /** Records neither the spool nor the database took, over this process. */
    public private(set) int $lost = 0;
    /** Lost records not counted in the settings yet (the database refused that too). */
    private int $lostUnsaved = 0;
    private bool $halted = false;

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
     * Takes the queue in batches. Each batch is sanitized, appended to the spool and its day marked pending before the
     * database write, and unmarked after it. When the database is away the queue is still taken (into the spool), so
     * nothing expires in CLIProxyAPI's queue; the first error is thrown at the end, for the alerts.
     *
     * @return int the usage records stored
     */
    public function collectUsage(): int
    {
        $this->usageOutcome = null;
        $stored = 0;
        $failure = null;
        try {
            $stored += $this->replayPending();
            $stored += $this->replayRecentIfDue();
        } catch (\Throwable $exception) {
            // The database (or the spool) is away: the queue is still taken below, into the spool.
            $failure = $exception;
            $this->doctrine->resetManager();
        }
        $done = $failed = [];
        do {
            // Taken records need one place at least: with neither the spool nor the database able to take them they
            // stay in CLIProxyAPI's queue (it keeps them an hour) instead of being taken into nowhere. Once a batch was
            // lost (a database that passed the probe and then refused the rows), only the spool resumes the taking.
            if (!$this->spool->writable()) {
                if ($this->takingHalted() || !$this->databaseWritable()) {
                    $this->usageOutcome = self::LEFT_IN_QUEUE;
                    $failure ??= new \RuntimeException('Neither the usage spool nor the database can take records');
                    break;
                }
            } else {
                $this->resumeTaking();
            }
            $records = array_map(UsageRecord::sanitize(...), $this->client->takeUsage(self::BATCH));
            // (store() sanitizes again: idempotent, and replays of older spool lines need it.)
            if ([] === $records) {
                break;
            }
            $day = null;
            $today = $this->spool->dayOf($this->clock->now());
            $wasPending = \in_array($today, $this->spool->pendingDays(), true);
            try {
                // The mark first: a crash right after the append still leaves the day to replay.
                $this->spool->markPending($today);
                $day = $this->spool->append($records, $this->clock->now());
            } catch (\Throwable $exception) {
                $failure ??= new \RuntimeException('Usage spool not written ('.$exception->getMessage().'): the records go to the database only', 0, $exception);
                // Nothing of this batch is in the spool: the day waits for nothing new.
                if (!$wasPending) {
                    try {
                        $this->spool->unmarkPending($today);
                    } catch (\Throwable) {
                    }
                }
            }
            if (null !== $day && $failure instanceof \Doctrine\DBAL\Exception) {
                // The database is still away: the batch waits in the spool.
                $this->usageOutcome ??= self::IN_SPOOL;
                continue;
            }
            try {
                $stored += $this->store($records);
                if (null !== $day) {
                    $done[$day] = true;
                }
            } catch (\Throwable $exception) {
                $failure ??= $exception;
                $this->doctrine->resetManager();
                if (null !== $day) {
                    $failed[$day] = true;
                    $this->usageOutcome ??= self::IN_SPOOL;
                    continue;
                }
                // Neither the spool nor the database took this batch: it is lost but for this copy on stderr (cron's
                // log), and no further batch is taken until the spool takes them again.
                $this->usageOutcome = self::LOST;
                $this->lost += \count($records);
                $this->lostUnsaved += \count($records);
                $this->halted = true;
                try {
                    $this->settings->set(Settings::USAGE_TAKING_HALTED, $this->clock->now()->format(\DATE_ATOM));
                    $this->saveLost();
                } catch (\Throwable) {
                    $this->doctrine->resetManager();
                }
                foreach ($records as $record) {
                    fwrite(\STDERR, 'usage-record-lost '.json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                }
                break;
            }
        } while (\count($records) === self::BATCH);
        foreach (array_keys(array_diff_key($done, $failed)) as $day) {
            $this->spool->unmarkPending($day);
        }
        if (null === $failure && $this->limitsChanged && $this->settings->autoPriority()) {
            $this->limitsChanged = false;
            $this->prioritizer->apply($this->client->accounts(), $this->snapshots->latest(), $this->clock->now());
        }
        if (null !== $failure) {
            throw $failure;
        }

        return $stored;
    }

    /** A write, not a read: a database that answers but cannot write (read-only, full) is no place for records. */
    private function databaseWritable(): bool
    {
        try {
            $this->settings->set(Settings::USAGE_WRITE_PROBE, $this->clock->now()->format(\DATE_ATOM));
            // A write statement on the table itself (no row): a read-only or missing table refuses it.
            $this->doctrine->getConnection()->executeStatement('UPDATE usage_record SET superseded = superseded WHERE id = 0');

            return true;
        } catch (\Throwable) {
            $this->doctrine->resetManager();

            return false;
        }
    }

    /** Adds the lost records not counted yet to the count kept for the alert (Settings::USAGE_LOST). */
    public function saveLost(): void
    {
        if (0 < $this->lostUnsaved) {
            $this->settings->set(Settings::USAGE_LOST, (int) $this->settings->get(Settings::USAGE_LOST) + $this->lostUnsaved);
            $this->lostUnsaved = 0;
        }
    }

    /** Whether a batch was lost since the spool last took records (this process, or one before it). */
    private function takingHalted(): bool
    {
        try {
            return $this->halted || '' !== (string) $this->settings->get(Settings::USAGE_TAKING_HALTED);
        } catch (\Throwable) {
            $this->doctrine->resetManager();

            return true;
        }
    }

    private function resumeTaking(): void
    {
        try {
            if ($this->halted || '' !== (string) $this->settings->get(Settings::USAGE_TAKING_HALTED)) {
                $this->settings->set(Settings::USAGE_TAKING_HALTED, '');
            }
            $this->halted = false;
        } catch (\Throwable) {
            // The database away: the spool takes the records meanwhile, the mark goes next time.
            $this->doctrine->resetManager();
        }
    }

    /** @return int the records of the day the database did not have yet */
    public function replay(string $day): int
    {
        $stored = 0;
        foreach ($this->spool->read($day) as $records) {
            $stored += $this->store($records);
        }
        // Lines no replay can read are kept as they are, and told about (Įspėjimai).
        $broken = (array) ($this->settings->get(Settings::USAGE_BROKEN_LINES) ?? []);
        if (0 !== $this->spool->brokenLines($day) || isset($broken[$day])) {
            $broken[$day] = $this->spool->brokenLines($day);
            $this->settings->set(Settings::USAGE_BROKEN_LINES, array_filter($broken));
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
        // A round under way (it did not fit one pass) or asked for: go on.
        if (true === $this->settings->get(Settings::QUOTA_REFRESH_REQUESTED) || null !== $this->settings->instant(Settings::QUOTA_ROUND_STARTED_AT)) {
            return true;
        }
        $last = $this->settings->instant(Settings::QUOTA_REFRESHED_AT);

        return null === $last
            || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $this->settings->quotaRefreshMinutes() * 60 - 5;
    }

    /**
     * Reads the accounts' limits, the longest unread first (a cursor by last attempt): when the 40-second budget runs
     * out, the next pass goes on where this one stopped. "Read" is marked only once every account had its turn.
     *
     * @return int the accounts read
     */
    public function refreshQuotas(): int
    {
        $accounts = array_values(array_filter($this->client->accounts(), static fn ($account) => $account->isClaude() || $account->isCodex()));
        $entityManager = $this->doctrine->getManager();
        $userAgents = $this->usage->latestUserAgents();
        $latest = $this->snapshots->latest();
        $attempted = (array) ($this->settings->get(Settings::QUOTA_ATTEMPTED_AT) ?? []);
        $errors = (array) ($this->settings->get(Settings::QUOTA_ERRORS) ?? []);
        // A round: every account once, over as many passes as it takes.
        $roundStart = $this->settings->instant(Settings::QUOTA_ROUND_STARTED_AT);
        if (null === $roundStart) {
            $roundStart = $this->clock->now();
            $this->settings->markNow(Settings::QUOTA_ROUND_STARTED_AT, $roundStart);
        }
        usort($accounts, static fn ($a, $b) => (int) ($attempted[$a->authIndex] ?? 0) <=> (int) ($attempted[$b->authIndex] ?? 0));
        // Each provider request may take 10 s; the whole read stays well inside the collector's minute.
        $deadline = microtime(true) + 40;
        $read = 0;
        foreach ($accounts as $account) {
            if (microtime(true) > $deadline) {
                break;
            }
            $snapshot = $this->quotaFetcher->fetch($account, $userAgents[$account->provider] ?? null);
            $attempted[$account->authIndex] = $this->clock->now()->getTimestamp();
            ++$read;
            if (null !== $snapshot->error) {
                $errors[$account->authIndex] = $this->clock->now()->format('Y-m-d H:i').': '.$snapshot->error;
            } else {
                unset($errors[$account->authIndex]);
            }
            $previous = $latest[$account->authIndex] ?? null;
            if ('headers' === $snapshot->source) {
                // A fallback with no headers, or none newer than what is known, measured nothing: only its error stays.
                if ([] === $snapshot->windows() || (null !== $previous && $snapshot->fetchedAt <= $previous->fetchedAt)) {
                    continue;
                }
                $snapshot = $snapshot->withMissingFrom($previous);
            }
            $entityManager->persist($snapshot);
        }
        $entityManager->flush();
        $this->settings->set(Settings::QUOTA_ATTEMPTED_AT, $attempted);
        $this->settings->set(Settings::QUOTA_ERRORS, $errors);
        $done = [] === array_filter($accounts, static fn ($account) => (int) ($attempted[$account->authIndex] ?? 0) < $roundStart->getTimestamp());
        if ($done) {
            $this->settings->markNow(Settings::QUOTA_REFRESHED_AT, $this->clock->now());
            $this->settings->set(Settings::QUOTA_REFRESH_REQUESTED, false);
            $this->settings->set(Settings::QUOTA_ROUND_STARTED_AT, null);
        }
        if ($this->settings->autoPriority()) {
            $this->prioritizer->apply($this->client->accounts(), $this->snapshots->latest(), $this->clock->now());
        }

        return $read;
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
            $this->spool->unmarkPending($day);
        }

        return $stored;
    }

    /**
     * Once an hour, today and yesterday from the spool again: whatever a crash or a bug kept out of the database comes
     * in (the record keys make it cheap and idempotent).
     */
    private function replayRecentIfDue(): int
    {
        $last = $this->settings->instant(Settings::USAGE_REPLAYED_AT);
        $now = $this->clock->now();
        if (null !== $last && $now->getTimestamp() - $last->getTimestamp() < 3600) {
            return 0;
        }
        $this->settings->markNow(Settings::USAGE_REPLAYED_AT, $now);
        $stored = 0;
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        foreach ([$utc->modify('-1 day')->format('Y-m-d'), $utc->format('Y-m-d')] as $day) {
            $stored += $this->replay($day);
        }

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
        $legacyKeys = [];
        foreach ($records as $record) {
            $row = UsageRecord::fromQueueRecord(UsageRecord::sanitize($record));
            $rows[$row->recordKey] = $row;
            // A record stored before sanitizing existed has the key of the record as it came.
            $legacyKeys[UsageRecord::keyOf($record)] = $row->recordKey;
        }
        foreach ($this->usage->existingKeys(array_merge(array_keys($rows), array_keys($legacyKeys))) as $key) {
            unset($rows[$legacyKeys[$key] ?? $key]);
        }
        $this->markRetries($rows);
        // A record's timestamp is when its request started; its headers are from when the answer came.
        $answeredAt = static fn (UsageRecord $row) => $row->occurredAt->modify('+'.max(0, $row->latencyMs).' milliseconds');
        $newestWithHeaders = [];
        foreach ($rows as $row) {
            $entityManager->persist($row);
            if ('' !== $row->authIndex && [] !== $row->rateLimitHeaders()
                && (!isset($newestWithHeaders[$row->authIndex]) || $answeredAt($row) > $answeredAt($newestWithHeaders[$row->authIndex]))) {
                $newestWithHeaders[$row->authIndex] = $row;
            }
        }
        $latest = [] === $newestWithHeaders ? [] : $this->snapshots->latest();
        foreach ($newestWithHeaders as $authIndex => $row) {
            $previous = $latest[$authIndex] ?? null;
            $at = $answeredAt($row);
            if (null !== $previous && $at <= $previous->fetchedAt) {
                continue;
            }
            $windows = [];
            foreach (QuotaFetcher::fromHeaders($row->rateLimitHeaders(), $at) as $window) {
                $windows[$window->key] = $window;
            }
            if ([] === $windows) {
                continue;
            }
            // At most one a minute, unless a window is at 90 % or more: the moment an account runs out is never skipped.
            $critical = [] !== array_filter($windows, static fn ($window) => $window->used >= 90);
            if (null !== $previous && !$critical && $at->getTimestamp() < $previous->fetchedAt->getTimestamp() + self::HEADER_SNAPSHOT_SECONDS) {
                continue;
            }
            // The headers know their windows; others (Claude's weekly Fable when it is not sent) carry over.
            foreach ($previous?->windows() ?? [] as $window) {
                $windows[$window->key] ??= $window;
            }
            $entityManager->persist(new QuotaSnapshot($authIndex, $row->account, $row->provider, $at,
                array_values($windows), 'headers', $previous?->plan, $previous?->bankedResets));
            $this->limitsChanged = true;
        }
        $entityManager->flush();
        $entityManager->clear();

        return \count($rows);
    }

    /**
     * Attempts of one request in the order they happened (to the microsecond; stored ones first on a tie, then the queue's
     * order): an attempt within two minutes after a failed one is its retry (CLIProxyAPI retries within
     * max-retry-interval), and the failed one is superseded – in this batch or already in the database. A Codex
     * WebSocket connection shares one request id across its turns; a turn after a successful one is no retry, and the
     * rare new turn within two minutes after a failed one is counted as its retry.
     *
     * @param array<string, UsageRecord> $rows
     */
    private function markRetries(array $rows): void
    {
        $byRequest = [];
        $order = 0;
        foreach ($rows as $row) {
            if ('' !== $row->requestId) {
                $byRequest[$row->requestId][] = ['at' => $row->occurredAt->getTimestamp() + $row->occurredMicros / 1e6, 'failed' => $row->failed, 'row' => $row, 'id' => null, 'order' => intdiv(\PHP_INT_MAX, 2) + ++$order];
            }
        }
        if ([] === $byRequest) {
            return;
        }
        foreach ($this->usage->attemptsOf(array_keys($byRequest)) as $attempt) {
            $byRequest[$attempt['request_id']][] = ['at' => $attempt['at'], 'failed' => $attempt['failed'], 'row' => null, 'id' => $attempt['id'], 'order' => $attempt['id']];
        }
        $supersede = [];
        foreach ($byRequest as $attempts) {
            usort($attempts, static fn (array $a, array $b) => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);
            $previous = null;
            foreach ($attempts as $attempt) {
                if (null !== $previous && $previous['failed'] && $attempt['at'] - $previous['at'] <= 120) {
                    if (null !== $attempt['row']) {
                        $attempt['row']->isRetry = true;
                    }
                    if (null !== $previous['row']) {
                        $previous['row']->superseded = true;
                    } elseif (null !== $previous['id']) {
                        $supersede[] = $previous['id'];
                    }
                }
                $previous = $attempt;
            }
        }
        if ([] !== $supersede) {
            $this->doctrine->getManager()->getConnection()->executeStatement('UPDATE usage_record SET superseded = 1 WHERE id IN (?)',
                [array_values(array_unique($supersede))], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
    }
}
