<?php

namespace App\CliProxy;

use App\Repository\UsageRecordRepository;
use App\Settings\Settings;

/**
 * What the hub needs from CLIProxyAPI's configuration, applied to the running config.yaml (the template only seeds an
 * empty state directory, so a change to it never reaches an existing hub):
 * - ALWAYS: the data policy – usage records queued for the collector, log files never deleted by CLIProxyAPI;
 * - ONCE (then the person's own choice in Nustatymai or config.yaml): a session moves to another account only when its
 *   own account runs out of quota, and a Claude model's quota cools down only that model, not the whole account;
 * - the Claude Code version CLIProxyAPI presents, kept at the newest client the hub has seen (ClaudeCodeVersion);
 * - Antigravity never answers for a model id a Claude account serves (shadowedByAntigravity).
 */
class ConfigBaseline
{
    public const int VERSION = 1;

    private const array ALWAYS = [
        'observability' => [
            'usage' => ['usage-statistics-enabled' => true, 'redis-usage-queue-retention-seconds' => 3600],
            'logs' => ['logs-max-total-size-mb' => 0, 'error-logs-max-files' => 0],
        ],
    ];
    private const array ONCE = [
        'routing' => ['session-affinity-rebind-on' => 'quota'],
        'upstream' => ['claude' => ['model-level-cooling' => true]],
    ];

    public function __construct(
        private readonly ManagementClient $client,
        private readonly Settings $settings,
        private readonly UsageRecordRepository $usage,
    ) {
    }

    /** @return bool whether the configuration was changed */
    public function apply(): bool
    {
        $config = $this->client->config();
        // Request logs are the traffic log: on unless switched off in Nustatymai (not through config.yaml by the way).
        $always = self::ALWAYS;
        $always['observability']['logs']['request-log'] = false !== $this->settings->get(Settings::REQUEST_LOG);
        $patch = self::missing($config, $always);
        $once = $this->settings->get(Settings::CONFIG_BASELINE) !== self::VERSION;
        if ($once) {
            $patch = array_replace_recursive($patch, self::missing($config, self::ONCE));
        }
        $agent = ClaudeCodeVersion::raise(ClaudeCodeVersion::configured($config), $this->usage->newestClaudeCodeVersion());
        if (null !== $agent) {
            $patch = array_replace_recursive($patch, ['upstream' => ['claude' => ['header-defaults' => ['user-agent' => $agent]]]]);
        }
        $excluded = $this->antigravityExclusions($config);
        if (null !== $excluded) {
            $patch = array_replace_recursive($patch, ['oauth' => ['excluded-models' => ['antigravity' => $excluded]]]);
        }
        if ([] !== $patch) {
            $this->client->patchConfig($patch);
        }
        if ($once) {
            $this->settings->set(Settings::CONFIG_BASELINE, self::VERSION);
        }

        return [] !== $patch;
    }

    /**
     * Antigravity serves some Claude models under the very ids the Claude accounts serve (claude-sonnet-4-6), and for
     * an id more providers have CLIProxyAPI picks among all their accounts by priority: a Claude Code request could
     * go to a Google account. Those ids are excluded from Antigravity (oauth.excluded-models.antigravity); its own
     * Claude ids (claude-opus-4-6-thinking, …) stay. The list only grows: an excluded id no longer shows in
     * Antigravity's models, so it cannot be told apart from one that went away.
     *
     * @param array<string, mixed> $config
     *
     * @return ?list<string> the whole new list, or null when nothing is to add
     */
    private function antigravityExclusions(array $config): ?array
    {
        $accounts = $this->client->accounts();
        $claude = array_values(array_filter($accounts, static fn (Account $a) => $a->isClaude()));
        $antigravity = array_filter($accounts, static fn (Account $a) => $a->isAntigravity());
        if ([] === $claude || [] === $antigravity) {
            return null;
        }
        $theirs = [];
        foreach ($antigravity as $account) {
            array_push($theirs, ...$this->client->credentialModels($account->name));
        }
        $current = $config['oauth']['excluded-models']['antigravity'] ?? [];

        return self::shadowedByAntigravity(\is_array($current) ? $current : [], $this->client->credentialModels($claude[0]->name), $theirs);
    }

    /**
     * @param list<mixed>  $current     oauth.excluded-models.antigravity now
     * @param list<string> $claude      a Claude account's model ids
     * @param list<string> $antigravity the Antigravity accounts' model ids
     *
     * @return ?list<string> $current with the shared ids added, or null when it has them all
     */
    public static function shadowedByAntigravity(array $current, array $claude, array $antigravity): ?array
    {
        $current = array_values(array_filter($current, 'is_string'));
        $known = array_map('strtolower', $current);
        $shared = array_values(array_unique(array_intersect(array_map('strtolower', $antigravity), array_map('strtolower', $claude))));
        $new = array_values(array_diff($shared, $known));
        if ([] === $new) {
            return null;
        }
        sort($new);

        return [...$current, ...$new];
    }

    /**
     * What a config.yaml saved from the editor may not do: the reasons it breaks the hub's data policy, as translation
     * keys (config_policy.* in translations/).
     *
     * @param array<string, mixed> $config the parsed YAML
     *
     * @return list<string>
     */
    public static function policyViolations(array $config, bool $requestLog): array
    {
        $violations = [];
        $logs = (array) ($config['observability']['logs'] ?? []);
        $usage = (array) ($config['observability']['usage'] ?? []);
        // Explicit values only: CLIProxyAPI's defaults for a missing key are not the hub's policy.
        if (0 !== ($logs['logs-max-total-size-mb'] ?? null) || 0 !== ($logs['error-logs-max-files'] ?? null)) {
            $violations[] = 'config_policy.log_cleanup';
        }
        if (true !== ($usage['usage-statistics-enabled'] ?? null)) {
            $violations[] = 'config_policy.usage_statistics';
        }
        if ($requestLog && true !== ($logs['request-log'] ?? null)) {
            $violations[] = 'config_policy.request_log';
        }
        // Full-duplex steering: CLIProxyAPI then logs one request per socket, and the journal's turns run together.
        // (and its older places, which CLIProxyAPI still reads)
        if (true === ($config['upstream']['codex']['response-steering'] ?? $config['codex']['response-steering'] ?? $config['oauth']['providers']['codex']['response-steering'] ?? false)) {
            $violations[] = 'config_policy.response_steering';
        }
        $keys = $config['access']['api-keys'] ?? null;
        if (!\is_array($keys) || [] === array_filter($keys, static fn ($key) => \is_string($key) && '' !== trim($key))) {
            $violations[] = 'config_policy.api_keys';
        }

        return $violations;
    }

    /**
     * The parts of $wanted that $config does not have yet.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $wanted
     *
     * @return array<string, mixed>
     */
    public static function missing(array $config, array $wanted): array
    {
        $patch = [];
        foreach ($wanted as $key => $value) {
            if (\is_array($value)) {
                $nested = self::missing(\is_array($config[$key] ?? null) ? $config[$key] : [], $value);
                if ([] !== $nested) {
                    $patch[$key] = $nested;
                }
            } elseif (($config[$key] ?? null) !== $value) {
                $patch[$key] = $value;
            }
        }

        return $patch;
    }
}
