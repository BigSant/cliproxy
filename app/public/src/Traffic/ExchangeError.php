<?php

namespace App\Traffic;

/**
 * The errors the journal itself gives an exchange (an upstream error is stored as it came). They are stored in
 * Lithuanian, the default language, as before, so search finds old and new alike; a page shows them in its own.
 */
final class ExchangeError
{
    public const string NO_REQUESTS = 'Ryšys be nė vienos užklausos';
    public const string TOO_LARGE = 'Failas per didelis išskaidyti (%d MB) – jis archyve, užklausos skaitomos tik iš jo.';
    public const string DISCONNECTED = 'Ryšys nutrūko: ';
    public const string UNFINISHED = 'Atsakymas nebaigtas';

    public static function tooLarge(int $bytes): string
    {
        return \sprintf(self::TOO_LARGE, intdiv($bytes, 1_048_576));
    }

    public static function disconnected(string $reason): string
    {
        return self::DISCONNECTED.$reason;
    }

    /** @return array{0: string, 1: array<string, string>}|null the translation key and parameters of one of the above */
    public static function parse(string $error): ?array
    {
        return match (true) {
            self::NO_REQUESTS === $error => ['traffic.error.no_requests', []],
            self::UNFINISHED === $error => ['traffic.error.unfinished', []],
            str_starts_with($error, self::DISCONNECTED) => ['traffic.error.disconnected', ['%reason%' => substr($error, \strlen(self::DISCONNECTED))]],
            1 === preg_match('/^'.str_replace('%d', '(\d+)', preg_quote(self::TOO_LARGE, '/')).'$/u', $error, $match) => ['traffic.error.too_large', ['%size%' => $match[1]]],
            default => null,
        };
    }
}
