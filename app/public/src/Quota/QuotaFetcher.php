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
    /** Antigravity's model catalog carries each model's quota; CLIProxyAPI asks the daily endpoint first, as here. */
    public const array ANTIGRAVITY_MODELS_URLS = [
        'https://daily-cloudcode-pa.googleapis.com/v1internal:fetchAvailableModels',
        'https://cloudcode-pa.googleapis.com/v1internal:fetchAvailableModels',
    ];
    /** The Antigravity desktop app, as CLIProxyAPI presents it (misc.AntigravityUserAgent). */
    private const string ANTIGRAVITY_USER_AGENT = 'antigravity/hub/2.33.0 (aidev_client; os_type=darwin; arch=arm64)';

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
                $account->isAntigravity() => $this->antigravity($account),
                default => null,
            };
            if (null !== $snapshot) {
                return $snapshot;
            }
        } catch (ManagementException|\UnexpectedValueException $exception) {
            $error = mb_substr($exception->getMessage(), 0, 255);
        }

        // The headers are as old as the answer that carried them, not "now".
        $observedAt = $account->signalsObservedAt ?? $this->clock->now();

        return new QuotaSnapshot($account->authIndex, $account->label(), $account->provider, $observedAt,
            self::fromHeaders($account->signals, $observedAt), 'headers', $account->planType, null, $error);
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

    private function antigravity(Account $account): QuotaSnapshot
    {
        $headers = [
            'Authorization' => 'Bearer $TOKEN$',
            'Content-Type' => 'application/json',
            'User-Agent' => self::ANTIGRAVITY_USER_AGENT,
        ];
        $data = (string) json_encode(null !== $account->projectId ? ['project' => $account->projectId] : new \stdClass());
        $body = null;
        foreach (self::ANTIGRAVITY_MODELS_URLS as $index => $url) {
            try {
                $body = $this->call($account, $url, $headers, 'POST', $data);
                break;
            } catch (\UnexpectedValueException $exception) {
                // The production endpoint is the fallback; its failure is the one told.
                if ($index === \count(self::ANTIGRAVITY_MODELS_URLS) - 1) {
                    throw $exception;
                }
            }
        }

        return new QuotaSnapshot($account->authIndex, $account->label(), $account->provider, $this->clock->now(),
            self::antigravityWindows((array) $body), 'live', $account->planType);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function call(Account $account, string $url, array $headers, string $method = 'GET', ?string $data = null): array
    {
        $answer = $this->client->providerCall($account->authIndex, $method, $url, $headers, $data);
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
     * Antigravity keeps a quota per model family, each with its own reset; one window per family, as used as its most
     * used model (a family shares one pool, so they agree). A model whose quota is gone has a reset but no remaining
     * fraction. Image, lite and Google's internal models (chat_*, tab_*) have pools of their own and stay out.
     *
     * @param array<string, mixed> $body fetchAvailableModels: {models: {<id>: {quotaInfo: {remainingFraction, resetTime}}}}
     *
     * @return list<QuotaWindow>
     */
    public static function antigravityWindows(array $body): array
    {
        $families = [];
        foreach ((array) ($body['models'] ?? []) as $id => $model) {
            $id = strtolower((string) $id);
            $quota = \is_array($model) ? ($model['quotaInfo'] ?? null) : null;
            if (!\is_array($quota) || str_contains($id, 'image') || str_contains($id, 'lite')) {
                continue;
            }
            $key = match (true) {
                str_starts_with($id, 'claude-') || str_starts_with($id, 'gpt-') => QuotaWindow::AG_CLAUDE,
                str_starts_with($id, 'gemini-') && str_contains($id, 'flash') => QuotaWindow::AG_FLASH,
                str_starts_with($id, 'gemini-') => QuotaWindow::AG_GEMINI,
                default => null,
            };
            $remaining = $quota['remainingFraction'] ?? null;
            $reset = self::instant($quota['resetTime'] ?? null);
            if (null === $key || (!is_numeric($remaining) && null === $reset)) {
                continue;
            }
            $used = is_numeric($remaining) ? max(0.0, min(100.0, (1 - (float) $remaining) * 100)) : 100.0;
            if (!isset($families[$key]) || $used > $families[$key][0]) {
                $families[$key] = [$used, $reset];
            }
        }
        $windows = [];
        foreach (QuotaWindow::ANTIGRAVITY as $key => $label) {
            if (isset($families[$key])) {
                $windows[] = new QuotaWindow($key, $label, round($families[$key][0], 2), $families[$key][1]);
            }
        }

        return $windows;
    }

    /**
     * The windows from the rate-limit headers of an answer (lower-case names), seen at $at.
     * - Claude: anthropic-ratelimit-unified-{5h,7d,7d_oi}-{utilization,reset}; utilization is a fraction (1.05 = 105 %),
     *   7d_oi is the weekly Fable window.
     * - Codex: x-codex-{primary,secondary}-used-percent, with the window's length in -window-minutes (300 = 5 h,
     *   10080 = 7 d; without it primary is the 5-hour one) and its reset in -reset-at (Unix) or -reset-after-seconds.
     *
     * @param array<string, string> $signals
     *
     * @return list<QuotaWindow>
     */
    public static function fromHeaders(array $signals, ?\DateTimeImmutable $at = null): array
    {
        $windows = [];
        foreach (['5h' => [QuotaWindow::FIVE_HOUR, '5 val.'], '7d' => [QuotaWindow::WEEK, '7 d.'], '7d_oi' => [QuotaWindow::WEEK_FABLE, '7 d. Fable']] as $suffix => [$key, $label]) {
            $used = $signals["anthropic-ratelimit-unified-$suffix-utilization"] ?? null;
            if (null !== $used && is_numeric($used)) {
                $windows[] = new QuotaWindow($key, $label, (float) $used * 100,
                    self::instant($signals["anthropic-ratelimit-unified-$suffix-reset"] ?? null));
            }
        }
        foreach (['primary' => QuotaWindow::FIVE_HOUR, 'secondary' => QuotaWindow::WEEK] as $name => $default) {
            $used = $signals["x-codex-$name-used-percent"] ?? null;
            if (null === $used || !is_numeric($used)) {
                continue;
            }
            $key = match ((int) ($signals["x-codex-$name-window-minutes"] ?? 0)) {
                300 => QuotaWindow::FIVE_HOUR,
                10080 => QuotaWindow::WEEK,
                default => $default,
            };
            $reset = self::instant($signals["x-codex-$name-reset-at"] ?? null);
            $after = $signals["x-codex-$name-reset-after-seconds"] ?? null;
            if (null === $reset && null !== $at && null !== $after && is_numeric($after)) {
                $reset = $at->modify('+'.(int) $after.' seconds');
            }
            $windows[] = new QuotaWindow($key, QuotaWindow::FIVE_HOUR === $key ? '5 val.' : '7 d.', (float) $used, $reset);
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
