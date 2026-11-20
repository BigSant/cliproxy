<?php

namespace App\Quota;

/** One limit window of a subscription: how much of it is used (percent) and when it starts over. */
final readonly class QuotaWindow
{
    public const string FIVE_HOUR = '5h';
    public const string WEEK = '7d';
    public const string WEEK_FABLE = '7d-fable';
    /** Antigravity's model families, each with its own quota and reset. */
    public const string AG_GEMINI = 'ag-gemini';
    public const string AG_FLASH = 'ag-flash';
    public const string AG_CLAUDE = 'ag-claude';
    public const array ANTIGRAVITY = [self::AG_GEMINI => 'Gemini Pro', self::AG_FLASH => 'Gemini Flash', self::AG_CLAUDE => 'Claude / GPT'];

    public function __construct(
        public string $key,
        public string $label,
        public float $used,
        public ?\DateTimeImmutable $resetsAt,
    ) {
    }

    /** The used share now: 0 once the window has reset, whatever the last reading said. */
    public function usedAt(\DateTimeImmutable $now): float
    {
        return null !== $this->resetsAt && $this->resetsAt <= $now ? 0.0 : $this->used;
    }

    public function isExhausted(?\DateTimeImmutable $now = null): bool
    {
        return (null === $now ? $this->used : $this->usedAt($now)) >= 99.5;
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
