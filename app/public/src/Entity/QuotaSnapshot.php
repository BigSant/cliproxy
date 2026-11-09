<?php

namespace App\Entity;

use App\Quota\QuotaWindow;
use App\Repository\QuotaSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account's subscription limits as the provider reported them at one moment: the 5-hour and weekly windows (and
 * Claude's weekly Fable window), how much of each is used and when it resets.
 */
#[ORM\Entity(repositoryClass: QuotaSnapshotRepository::class)]
#[ORM\Index(name: 'quota_account', columns: ['auth_index', 'fetched_at'])]
class QuotaSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<array{key: string, label: string, used: float, resetsAt: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $windows;

    /** @param list<QuotaWindow> $windows */
    public function __construct(
        #[ORM\Column(length: 64)]
        public readonly string $authIndex,
        #[ORM\Column(length: 190)]
        public readonly string $account,
        #[ORM\Column(length: 32)]
        public readonly string $provider,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly \DateTimeImmutable $fetchedAt,
        array $windows,
        /** live: asked the provider; headers: the rate-limit headers of the last proxied answer. */
        #[ORM\Column(length: 16)]
        public readonly string $source,
        #[ORM\Column(length: 64, nullable: true)]
        public readonly ?string $plan = null,
        /** Codex: resets of the weekly limit the account has banked. */
        #[ORM\Column(nullable: true)]
        public readonly ?int $bankedResets = null,
        #[ORM\Column(length: 255, nullable: true)]
        public readonly ?string $error = null,
    ) {
        $this->windows = array_map(static fn (QuotaWindow $window) => $window->toArray(), $windows);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return list<QuotaWindow> */
    public function windows(): array
    {
        return array_map(QuotaWindow::fromArray(...), $this->windows);
    }

    /** This snapshot with the windows it lacks taken from $previous (the headers carry no Fable window, …). */
    public function withMissingFrom(?self $previous): self
    {
        if (null === $previous) {
            return $this;
        }
        $windows = [];
        foreach ($this->windows() as $window) {
            $windows[$window->key] = $window;
        }
        foreach ($previous->windows() as $window) {
            $windows[$window->key] ??= $window;
        }

        return new self($this->authIndex, $this->account, $this->provider, $this->fetchedAt, array_values($windows), $this->source,
            $this->plan ?? $previous->plan, $this->bankedResets ?? $previous->bankedResets, $this->error);
    }

    public function window(string $key): ?QuotaWindow
    {
        foreach ($this->windows() as $window) {
            if ($window->key === $key) {
                return $window;
            }
        }

        return null;
    }
}
