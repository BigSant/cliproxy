<?php

namespace App\CliProxy;

/**
 * One signed-in subscription (a CLIProxyAPI credential file): a Claude or Codex account the hub routes requests to.
 * Built from an entry of GET /v8/management/credentials.
 */
final readonly class Account
{
    /**
     * @param array<string, string> $signals the provider's latest rate-limit headers, seen on the last proxied answer
     * @param list<array{scope: string, model: string, reason: string, retryAt: ?\DateTimeImmutable}> $cooldowns
     */
    public function __construct(
        public string $name,
        public string $authIndex,
        public string $provider,
        public string $email,
        public string $status,
        public string $statusMessage,
        public bool $disabled,
        public bool $unavailable,
        public int $priority,
        public int $weight,
        public string $note,
        public bool $websockets,
        public int $success,
        public int $failed,
        public ?string $planType,
        public ?string $chatgptAccountId,
        public array $signals,
        public array $cooldowns,
        public ?\DateTimeImmutable $lastRefresh,
        public bool $requiresSignIn,
    ) {
    }

    /** @param array<string, mixed> $file */
    public static function fromArray(array $file): self
    {
        $idToken = \is_array($file['id_token'] ?? null) ? $file['id_token'] : [];
        $signals = [];
        foreach ((array) ($file['quota']['signals'] ?? []) as $header => $value) {
            $signals[strtolower((string) $header)] = \is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $cooldowns = [];
        foreach ((array) ($file['cooldowns'] ?? []) as $cooldown) {
            if (\is_array($cooldown)) {
                $cooldowns[] = [
                    'scope' => (string) ($cooldown['scope'] ?? ''),
                    'model' => (string) ($cooldown['model_key'] ?? ''),
                    'reason' => (string) ($cooldown['reason'] ?? ''),
                    'retryAt' => self::date($cooldown['retry_at'] ?? null),
                ];
            }
        }
        $provider = (string) ($file['provider'] ?? $file['type'] ?? '');

        return new self(
            name: (string) ($file['name'] ?? $file['id'] ?? ''),
            authIndex: (string) ($file['auth_index'] ?? ''),
            provider: 'anthropic' === $provider ? 'claude' : $provider,
            email: (string) ($file['email'] ?? $file['account'] ?? $file['label'] ?? ''),
            status: (string) ($file['status'] ?? ''),
            statusMessage: (string) ($file['status_message'] ?? ''),
            disabled: (bool) ($file['disabled'] ?? false),
            unavailable: (bool) ($file['unavailable'] ?? false),
            priority: (int) ($file['priority'] ?? 0),
            weight: (int) ($file['weight'] ?? 1),
            note: (string) ($file['note'] ?? ''),
            websockets: (bool) ($file['websockets'] ?? false),
            success: (int) ($file['success'] ?? 0),
            failed: (int) ($file['failed'] ?? 0),
            planType: isset($file['plan_type']) ? (string) $file['plan_type'] : (isset($idToken['plan_type']) ? (string) $idToken['plan_type'] : null),
            chatgptAccountId: isset($idToken['chatgpt_account_id']) ? (string) $idToken['chatgpt_account_id'] : null,
            signals: $signals,
            cooldowns: $cooldowns,
            lastRefresh: self::date($file['last_refresh'] ?? null),
            requiresSignIn: (bool) ($file['requires_sign_in'] ?? false),
        );
    }

    public function label(): string
    {
        return '' !== $this->email ? $this->email : $this->name;
    }

    public function isClaude(): bool
    {
        return 'claude' === $this->provider;
    }

    public function isCodex(): bool
    {
        return 'codex' === $this->provider;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value || str_starts_with($value, '0001-')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
