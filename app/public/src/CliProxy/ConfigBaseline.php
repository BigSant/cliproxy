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
        // Explicit values only: CLIProxyAPI's defaults for a missing key are not the hub's policy.
        if (0 !== ($logs['logs-max-total-size-mb'] ?? null) || 0 !== ($logs['error-logs-max-files'] ?? null)) {
            $violations[] = 'log failų valymas (observability.logs: logs-max-total-size-mb ir error-logs-max-files turi būti nurodyti ir lygūs 0)';
        }
        if (true !== ($usage['usage-statistics-enabled'] ?? null)) {
            $violations[] = 'usage statistika neįjungta (observability.usage.usage-statistics-enabled: true)';
        }
        if ($requestLog && true !== ($logs['request-log'] ?? null)) {
            $violations[] = 'request-log neįjungtas (observability.logs.request-log: true; išjunk Nustatymuose → Srauto žurnalas, jei tikrai reikia)';
        }
        // Full-duplex steering: CLIProxyAPI then logs one request per socket, and the journal's turns run together.
        // (and its older places, which CLIProxyAPI still reads)
        if (true === ($config['upstream']['codex']['response-steering'] ?? $config['codex']['response-steering'] ?? $config['oauth']['providers']['codex']['response-steering'] ?? false)) {
            $violations[] = 'upstream.codex.response-steering įjungtas – srauto žurnalas nebeatskirtų WS turn\'ų';
        }
        $keys = $config['access']['api-keys'] ?? null;
        if (!\is_array($keys) || [] === array_filter($keys, static fn ($key) => \is_string($key) && '' !== trim($key))) {
            $violations[] = 'nėra nė vieno kliento rakto (access.api-keys) – autentifikacija būtų išjungta';
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
