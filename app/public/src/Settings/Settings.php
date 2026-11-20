<?php

namespace App\Settings;

use Doctrine\ORM\EntityManagerInterface;

/** The dashboard's own settings and the collector's marks, stored in the database. */
class Settings
{
    public const string AUTO_PRIORITY = 'auto_priority';
    public const string QUOTA_REFRESH_MINUTES = 'quota_refresh_minutes';
    public const string QUOTA_REFRESHED_AT = 'quota_refreshed_at';
    /** Set by the dashboard's "read now"; the collector reads the limits on its next pass. */
    public const string QUOTA_REFRESH_REQUESTED = 'quota_refresh_requested';
    /** The end of the collector's last pass and its last error (Apžvalga warns when it stops). */
    public const string COLLECTOR_HEARTBEAT = 'collector_heartbeat';
    public const string COLLECTOR_ERROR = 'collector_error';
    /** ntfy topic URL for pushes (https://ntfy.sh/<topic> or a self-hosted one); empty: alerts only in Įspėjimai. */
    public const string NTFY_URL = 'ntfy_url';
    /** CLIProxyAPI's running and newest release, and when the newest was last asked (app:cliproxy-update). */
    public const string CLIPROXY_VERSION = 'cliproxy_version';
    public const string CLIPROXY_LATEST = 'cliproxy_latest';
    public const string CLIPROXY_LATEST_CHECKED_AT = 'cliproxy_latest_checked_at';
    /** The update to the newest release: {version, state (CliProxyUpdateCommand), at, error?}. */
    public const string CLIPROXY_UPDATE = 'cliproxy_update';
    /** The harnesses' newest npm releases (version by harness), and the last error bringing CLIProxyAPI up to them. */
    public const string HARNESS_LATEST = 'harness_latest';
    public const string HARNESS_ERROR = 'harness_error';
    /** The configuration baseline applied once (ConfigBaseline::ONCE). */
    public const string CONFIG_BASELINE = 'config_baseline';
    /** The collector's hourly replay of today's and yesterday's spool. */
    public const string USAGE_REPLAYED_AT = 'usage_replayed_at';
    /** The last limits read's errors, by auth index (Paskyros shows them). */
    public const string QUOTA_ERRORS = 'quota_errors';
    /** When each account's limits were last asked for, by auth index: the read goes on from the oldest. */
    public const string QUOTA_ATTEMPTED_AT = 'quota_attempted_at';
    /** The start of a limits round still under way (null: none). */
    public const string QUOTA_ROUND_STARTED_AT = 'quota_round_started_at';
    /** Spool lines a replay could not read, by day. */
    public const string USAGE_BROKEN_LINES = 'usage_broken_lines';
    /** The broken lines (by day) the person has seen: the alert is for more than these. */
    public const string USAGE_BROKEN_LINES_SEEN = 'usage_broken_lines_seen';
    /** Written to tell whether the database takes writes (when the usage spool does not). */
    public const string USAGE_WRITE_PROBE = 'usage_write_probe';
    /** Set when a usage batch was lost: the queue is taken again only once the spool can hold it. */
    public const string USAGE_TAKING_HALTED = 'usage_taking_halted';
    /** Usage records lost so far (neither the spool nor the database took them), and those the person has seen. */
    public const string USAGE_LOST = 'usage_lost';
    public const string USAGE_LOST_SEEN = 'usage_lost_seen';
    /** The traffic log (CLIProxyAPI request-log): on unless false; ConfigBaseline keeps CLIProxyAPI to it. */
    public const string REQUEST_LOG = 'request_log';
    /** Set by Įspėjimai; the collector moves the quarantined request logs back into logs/. */
    public const string QUARANTINE_RETRY_REQUESTED = 'quarantine_retry_requested';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Soonest-reset-first: the collector orders account priorities by when their weekly limit resets. */
    public function autoPriority(): bool
    {
        return true === $this->get(self::AUTO_PRIORITY);
    }

    public function quotaRefreshMinutes(): int
    {
        $minutes = $this->get(self::QUOTA_REFRESH_MINUTES);

        return \is_int($minutes) && $minutes >= 1 ? $minutes : 5;
    }

    public function instant(string $name): ?\DateTimeImmutable
    {
        $value = $this->get($name);

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    public function markNow(string $name, \DateTimeImmutable $now): void
    {
        $this->set($name, $now->format(\DATE_ATOM));
    }

    /**
     * Read from the database every time, not from the entity manager: the collector runs for a minute and must see
     * what the dashboard changed meanwhile ("read now", auto priority).
     */
    public function get(string $name): mixed
    {
        $value = $this->connection()->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);

        return false === $value || null === $value ? null : json_decode((string) $value, true);
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $connection = $this->connection();
        if (false === $connection->fetchOne('SELECT 1 FROM setting WHERE name = ?', [$name])) {
            $connection->insert('setting', ['name' => $name, 'value' => $json]);
        } else {
            $connection->update('setting', ['value' => $json], ['name' => $name]);
        }
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }
}
