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
    public const string PRUNED_AT = 'pruned_at';
    public const string ARCHIVE_DAYS = 'archive_days';

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

    /** How long raw request logs stay in the archive; the parsed exchanges stay 90 days. */
    public function archiveDays(): int
    {
        $days = $this->get(self::ARCHIVE_DAYS);

        return \is_int($days) && $days >= 1 ? $days : 14;
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
