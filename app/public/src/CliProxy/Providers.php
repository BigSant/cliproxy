<?php

namespace App\CliProxy;

/** The providers whose accounts the hub signs in, keyed as CLIProxyAPI names them, with the name a person reads. */
final class Providers
{
    public const string CLAUDE = 'claude';
    public const string CODEX = 'codex';
    /** Google Antigravity: Gemini (and Google's Claude and GPT-OSS quota) through a Google account. */
    public const string ANTIGRAVITY = 'antigravity';

    public const array LABELS = [self::CLAUDE => 'Claude', self::CODEX => 'Codex', self::ANTIGRAVITY => 'Antigravity'];

    /** The subscriptions with a 5-hour and a weekly limit (the quota page, soonest-reset-first). */
    public const array WINDOWED = [self::CLAUDE, self::CODEX];

    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }
}
