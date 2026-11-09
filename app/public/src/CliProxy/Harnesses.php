<?php

namespace App\CliProxy;

/**
 * The client versions CLIProxyAPI presents upstream: Claude Code to Anthropic, Codex to OpenAI. A request CLIProxyAPI
 * does not pass through as the client's own goes out under these, and Anthropic refuses newer models to older Claude
 * Code ("Claude Code 2.1.280 does not support this model"). CLIProxyAPI's built-in ones are the releases it last
 * measured, so app:cliproxy-update keeps them at the newest npm releases (README, "CLIProxyAPI atnaujinimas"): raised,
 * never lowered, so a higher value set by hand in config.yaml stays.
 */
final class Harnesses
{
    /** @var array<string, array{package: string, path: list<string>, agent: string, pattern: string}> */
    public const array ALL = [
        'claude' => [
            'package' => '@anthropic-ai/claude-code',
            'path' => ['upstream', 'claude', 'header-defaults', 'user-agent'],
            'agent' => 'claude-cli/%s (external, cli)',
            'pattern' => '~^claude-cli/(\d+\.\d+\.\d+)(?![\d.])~',
        ],
        // CLIProxyAPI's own default with the version in both places; only for requests that send no User-Agent.
        'codex' => [
            'package' => '@openai/codex',
            'path' => ['oauth', 'providers', 'codex', 'header-defaults', 'user-agent'],
            'agent' => 'codex-tui/%1$s (Mac OS 26.5.2; arm64) iTerm.app/3.6.11 (codex-tui; %1$s)',
            'pattern' => '~^codex[a-z_-]*/(\d+\.\d+\.\d+)(?![\d.])~',
        ],
    ];

    /** @param array<string, mixed> $config the User-Agent configured for $harness, or null for CLIProxyAPI's own */
    public static function configured(array $config, string $harness): ?string
    {
        $value = $config;
        foreach (self::ALL[$harness]['path'] as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    public static function version(string $harness, string $userAgent): ?string
    {
        return preg_match(self::ALL[$harness]['pattern'], $userAgent, $match) ? $match[1] : null;
    }

    /**
     * The config patch that brings every harness behind its newest release up to it.
     *
     * @param array<string, mixed>  $config
     * @param array<string, string> $latest version by harness
     *
     * @return array<string, mixed>
     */
    public static function patch(array $config, array $latest): array
    {
        $patch = [];
        foreach ($latest as $harness => $version) {
            $configured = self::configured($config, $harness);
            $current = null !== $configured ? self::version($harness, $configured) : null;
            if (null !== $current && !version_compare($version, $current, '>')) {
                continue;
            }
            $value = \sprintf(self::ALL[$harness]['agent'], $version);
            foreach (array_reverse(self::ALL[$harness]['path']) as $key) {
                $value = [$key => $value];
            }
            $patch = array_replace_recursive($patch, $value);
        }

        return $patch;
    }
}
