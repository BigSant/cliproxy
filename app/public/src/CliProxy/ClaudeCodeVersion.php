<?php

namespace App\CliProxy;

/**
 * The Claude Code version CLIProxyAPI presents to Anthropic (upstream.claude.header-defaults.user-agent). Every request
 * it does not pass through as a native Claude Code client's goes out with that version, and Anthropic refuses newer
 * models to versions older than they need ("Claude Code 2.1.280 does not support this model"). CLIProxyAPI's own
 * default is the release it last measured, so the hub keeps it at the newest version the people's clients run: raised
 * when a newer client is seen, never lowered (a higher value set by hand stays).
 */
final class ClaudeCodeVersion
{
    public const string PATH = 'upstream.claude.header-defaults.user-agent';

    /** X.Y.Z of a claude-cli/X.Y.Z User-Agent, or null. */
    public static function of(string $userAgent): ?string
    {
        return preg_match('~^claude-cli/(\d+\.\d+\.\d+)(?![\d.])~', trim($userAgent), $match) ? $match[1] : null;
    }

    /** The User-Agent to write when the newest client is ahead of the configured one (or none is configured). */
    public static function raise(?string $configured, ?string $newestSeen): ?string
    {
        if (null === $newestSeen) {
            return null;
        }
        $current = null !== $configured ? self::of($configured) : null;
        if (null !== $current && !version_compare($newestSeen, $current, '>')) {
            return null;
        }

        return "claude-cli/$newestSeen (external, cli)";
    }

    /** @param array<string, mixed> $config */
    public static function configured(array $config): ?string
    {
        $agent = $config['upstream']['claude']['header-defaults']['user-agent'] ?? null;

        return \is_string($agent) && '' !== trim($agent) ? trim($agent) : null;
    }
}
