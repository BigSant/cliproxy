<?php

namespace App\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
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
    /** CLIProxyAPI's running and newest release, and when the newest was last asked. */
    public const string CLIPROXY_VERSION = 'cliproxy_version';
    public const string CLIPROXY_LATEST = 'cliproxy_latest';
    public const string CLIPROXY_LATEST_CHECKED_AT = 'cliproxy_latest_checked_at';
    /** The configuration baseline applied once (ConfigBaseline::ONCE). */
    public const string CONFIG_BASELINE = 'config_baseline';

    public function __construct(
        private readonly SettingRepository $repository,
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

    public function get(string $name): mixed
    {
        return $this->repository->find($name)?->value;
    }

    public function set(string $name, mixed $value): void
    {
        $setting = $this->repository->find($name);
        if (null === $setting) {
            $this->entityManager->persist(new Setting($name, $value));
        } else {
            $setting->value = $value;
        }
        $this->entityManager->flush();
    }
}
