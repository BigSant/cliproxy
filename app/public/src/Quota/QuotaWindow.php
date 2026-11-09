<?php

namespace App\Quota;

/** One limit window of a subscription: how much of it is used (percent) and when it starts over. */
final readonly class QuotaWindow
{
    public const string FIVE_HOUR = '5h';
    public const string WEEK = '7d';
    public const string WEEK_FABLE = '7d-fable';

    public function __construct(
        public string $key,
        public string $label,
        public float $used,
        public ?\DateTimeImmutable $resetsAt,
    ) {
    }

    public function isExhausted(): bool
    {
        return $this->used >= 99.5;
    }

    /** @return array{key: string, label: string, used: float, resetsAt: ?string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'used' => $this->used,
            'resetsAt' => $this->resetsAt?->format(\DATE_ATOM),
        ];
    }

    /** @param array{key: string, label: string, used: float|int, resetsAt: ?string} $window */
    public static function fromArray(array $window): self
    {
        return new self(
            $window['key'],
            $window['label'],
            (float) $window['used'],
            null !== $window['resetsAt'] ? new \DateTimeImmutable($window['resetsAt']) : null,
        );
    }
}
