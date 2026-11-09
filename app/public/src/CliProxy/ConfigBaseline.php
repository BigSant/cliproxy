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
        $patch = self::missing($config, self::ALWAYS);
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
