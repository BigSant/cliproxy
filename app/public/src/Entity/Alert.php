<?php

namespace App\Entity;

use App\Repository\AlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One episode of a condition that needs the person (Įspėjimai): an account to sign in again, a provider with no usable
 * account, CLIProxyAPI not answering, files in quarantine, little disk left, a new CLIProxyAPI release. A condition
 * (its key) that clears and comes back is a new episode; the earlier ones stay.
 */
#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Index(name: 'alert_open', columns: ['resolved_at'])]
#[ORM\Index(name: 'alert_key', columns: ['alert_key'])]
class Alert
{
    public const string ERROR = 'error';
    public const string WARNING = 'warning';
    public const string INFO = 'info';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    public \DateTimeImmutable $lastSeenAt;
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $notifiedAt = null;

    public function __construct(
        #[ORM\Column(length: 190)]
        public readonly string $alertKey,
        #[ORM\Column(length: 16)]
        public string $level,
        #[ORM\Column(length: 500)]
        public string $message,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public \DateTimeImmutable $firstSeenAt,
    ) {
        $this->lastSeenAt = $firstSeenAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isOpen(): bool
    {
        return null === $this->resolvedAt;
    }
}
