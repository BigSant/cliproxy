<?php

namespace App\Entity;

use App\Repository\AlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A condition that needs the person (Įspėjimai): an account to sign in again, a provider with no usable account,
 * CLIProxyAPI not answering, files in quarantine, little disk left, a new CLIProxyAPI release. Kept after it clears,
 * with when it started and ended.
 */
#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Index(name: 'alert_open', columns: ['resolved_at'])]
class Alert
{
    public const string ERROR = 'error';
    public const string WARNING = 'warning';
    public const string INFO = 'info';

    #[ORM\Column(type: 'utc_datetime_immutable')]
    public \DateTimeImmutable $lastSeenAt;
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $notifiedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 190)]
        public readonly string $id,
        #[ORM\Column(length: 16)]
        public string $level,
        #[ORM\Column(length: 500)]
        public string $message,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public \DateTimeImmutable $firstSeenAt,
    ) {
        $this->lastSeenAt = $firstSeenAt;
    }

    public function isOpen(): bool
    {
        return null === $this->resolvedAt;
    }
}
