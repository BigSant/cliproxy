<?php

namespace App\Quota;

use App\CliProxy\Account;
use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Entity\QuotaSnapshot;
use Psr\Clock\ClockInterface;

/**
 * Reads an account's subscription limits. It asks the provider's usage endpoint with the account's own token, through
 * CLIProxyAPI (/requests/api-call), as the official management panel does; when that fails it falls back to the
 * rate-limit headers CLIProxyAPI saw on the account's last proxied answer.
 */
class QuotaFetcher
{
    public const string CLAUDE_USAGE_URL = 'https://api.anthropic.com/api/oauth/usage';
    public const string CODEX_USAGE_URL = 'https://chatgpt.com/backend-api/wham/usage';

    private const array CLAUDE_WINDOWS = [
        'five_hour' => [QuotaWindow::FIVE_HOUR, '5 val.'],
        'seven_day' => [QuotaWindow::WEEK, '7 d.'],
        // Claude's weekly Fable limit.
        'iguana_necktie' => [QuotaWindow::WEEK_FABLE, '7 d. Fable'],
        'seven_day_opus' => ['7d-opus', '7 d. Opus'],
        'seven_day_sonnet' => ['7d-sonnet', '7 d. Sonnet'],
    ];

    public function __construct(
        private readonly ManagementClient $client,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param ?string $userAgent the newest real client's, so the request looks like that client */
    public function fetch(Account $account, ?string $userAgent = null): QuotaSnapshot
    {
        $error = null;
        try {
            $snapshot = match (true) {
                $account->isClaude() => $this->claude($account, $userAgent),
                $account->isCodex() => $this->codex($account, $userAgent),
                default => null,
            };
            if (null !== $snapshot) {
                return $snapshot;
            }
        } catch (ManagementException|\UnexpectedValueException $exception) {
            $error = mb_substr($exception->getMessage(), 0, 255);
        }

        // The headers are as old as the answer that carried them, not "now".
        return new QuotaSnapshot($account->authIndex, $account->label(), $account->provider, $account->signalsObservedAt ?? $this->clock->now(),
            self::fromHeaders($account->signals), 'headers', $account->planType, null, $error);
    }

    private function claude(Account $account, ?string $userAgent): QuotaSnapshot
    {
        $body = $this->call($account, self::CLAUDE_USAGE_URL, [
            'Authorization' => 'Bearer $TOKEN$',
            'anthropic-beta' => 'oauth-2025-04-20',
            'User-Agent' => $userAgent ?? 'claude-cli/2.1.312 (external, cli)',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return new QuotaSnapshot($account->authIndex, $account->label(), $account->provider, $this->clock->now(),
            self::claudeWindows($body), 'live', $account->planType);
    }

    private function codex(Account $account, ?string $userAgent): QuotaSnapshot
    {
        $headers = [
            'Authorization' => 'Bearer $TOKEN$',
            'Content-Type' => 'application/json',
            'User-Agent' => $userAgent ?? 'codex_cli_rs/0.149.1',
        ];
        if (null !== $account->chatgptAccountId) {
            $headers['Chatgpt-Account-Id'] = $account->chatgptAccountId;
        }
        $body = $this->call($account, self::CODEX_USAGE_URL, $headers);
        $banked = $body['rate_limit_reset_credits']['available_count'] ?? null;

        return new QuotaSnapshot($account->authIndex, $account->label(), $account->provider, $this->clock->now(),
            self::codexWindows($body), 'live', isset($body['plan_type']) ? (string) $body['plan_type'] : $account->planType,
            null !== $banked ? (int) $banked : null);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function call(Account $account, string $url, array $headers): array
    {
        $answer = $this->client->providerCall($account->authIndex, 'GET', $url, $headers);
        if (200 !== $answer['status_code']) {
            throw new \UnexpectedValueException(\sprintf('Provider answered %d', $answer['status_code']));
        }
        $body = json_decode($answer['body'], true);
        if (!\is_array($body)) {
            throw new \UnexpectedValueException('Provider answer is not JSON');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body api/oauth/usage: {five_hour: {utilization, resets_at}, seven_day: …}
     *
     * @return list<QuotaWindow>
     */
    public static function claudeWindows(array $body): array
    {
        $windows = [];
        foreach (self::CLAUDE_WINDOWS as $field => [$key, $label]) {
            $window = $body[$field] ?? null;
            if (!\is_array($window) || !isset($window['utilization'])) {
                continue;
            }
            $windows[] = new QuotaWindow($key, $label, (float) $window['utilization'], self::instant($window['resets_at'] ?? null));
        }

        return $windows;
    }

    /**
     * @param array<string, mixed> $body wham/usage: {rate_limit: {primary_window: {used_percent, limit_window_seconds, reset_at}, secondary_window: …}}
     *
     * @return list<QuotaWindow>
     */
    public static function codexWindows(array $body): array
    {
        $windows = [];
        foreach (['primary_window' => QuotaWindow::FIVE_HOUR, 'secondary_window' => QuotaWindow::WEEK] as $field => $default) {
            $window = $body['rate_limit'][$field] ?? null;
            if (!\is_array($window) || !isset($window['used_percent'])) {
                continue;
            }
            // Older answers leave the length out: primary is the 5-hour window, secondary the weekly one.
            $key = match ((int) ($window['limit_window_seconds'] ?? 0)) {
                18000 => QuotaWindow::FIVE_HOUR,
                604800 => QuotaWindow::WEEK,
                default => $default,
            };
            $windows[] = new QuotaWindow($key, QuotaWindow::FIVE_HOUR === $key ? '5 val.' : '7 d.',
                (float) $window['used_percent'], self::instant($window['reset_at'] ?? null));
        }

        return $windows;
    }

    /**
     * The windows from the rate-limit headers of the last proxied answer (lower-case names).
     *
     * @param array<string, string> $signals
     *
     * @return list<QuotaWindow>
     */
    public static function fromHeaders(array $signals): array
    {
        $windows = [];
        foreach (['5h' => [QuotaWindow::FIVE_HOUR, '5 val.'], '7d' => [QuotaWindow::WEEK, '7 d.']] as $suffix => [$key, $label]) {
            $used = $signals["anthropic-ratelimit-unified-$suffix-utilization"] ?? null;
            if (null !== $used && is_numeric($used)) {
                // Anthropic's headers give a fraction (0.42), its usage endpoint a percentage.
                $windows[] = new QuotaWindow($key, $label, (float) $used <= 1.0 ? (float) $used * 100 : (float) $used,
                    self::instant($signals["anthropic-ratelimit-unified-$suffix-reset"] ?? null));
            }
        }
        foreach (['primary' => [QuotaWindow::FIVE_HOUR, '5 val.'], 'secondary' => [QuotaWindow::WEEK, '7 d.']] as $name => [$key, $label]) {
            $used = $signals["x-codex-$name-used-percent"] ?? null;
            if (null !== $used && is_numeric($used)) {
                $windows[] = new QuotaWindow($key, $label, (float) $used, self::instant($signals["x-codex-$name-reset-at"] ?? null));
            }
        }

        return $windows;
    }

    /** An ISO-8601 string or Unix seconds. */
    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? new \DateTimeImmutable('@'.(int) $value) : null;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
