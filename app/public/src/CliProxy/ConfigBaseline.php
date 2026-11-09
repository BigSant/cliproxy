<?php

namespace App\CliProxy;

use App\Settings\Settings;

/**
 * What the hub needs from CLIProxyAPI's configuration, applied to the running config.yaml (the template only seeds an
 * empty state directory, so a change to it never reaches an existing hub):
 * - ALWAYS: the data policy – usage records queued for the collector, log files never deleted by CLIProxyAPI;
 * - ONCE (then the person's own choice in Nustatymai or config.yaml): a session moves to another account only when its
 *   own account runs out of quota, and a Claude model's quota cools down only that model, not the whole account.
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
        if ([] !== $patch) {
            $this->client->patchConfig($patch);
        }
        if ($once) {
            $this->settings->set(Settings::CONFIG_BASELINE, self::VERSION);
        }

        return [] !== $patch;
    }

    /**
     * What a config.yaml saved from the editor may not do: the reasons it breaks the hub's data policy.
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
        if ((int) ($logs['logs-max-total-size-mb'] ?? 0) > 0 || (int) ($logs['error-logs-max-files'] ?? 0) > 0) {
            $violations[] = 'log failų valymas (logs-max-total-size-mb, error-logs-max-files turi būti 0)';
        }
        if (false === ($usage['usage-statistics-enabled'] ?? true)) {
            $violations[] = 'usage statistika išjungta';
        }
        if ($requestLog && false === ($logs['request-log'] ?? true)) {
            $violations[] = 'request-log išjungtas (išjunk Nustatymuose → Srauto žurnalas, jei tikrai reikia)';
        }
        if ([] === ($config['access']['api-keys'] ?? null)) {
            $violations[] = 'tuščias klientų raktų sąrašas išjungtų autentifikaciją';
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
