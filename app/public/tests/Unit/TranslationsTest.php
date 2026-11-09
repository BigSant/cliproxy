<?php

namespace App\Tests\Unit;

use App\Traffic\ExchangeError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** LT and EN say the same things, and every key the code and templates name is in both. */
class TranslationsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testBothLanguagesHaveTheSameKeys(): void
    {
        $lt = self::catalogue('lt');
        $en = self::catalogue('en');

        self::assertSame([], array_values(array_diff(array_keys($lt), array_keys($en))), 'only in Lithuanian');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($lt))), 'only in English');
    }

    public function testEveryKeyNamedInTheCodeExists(): void
    {
        $catalogue = self::catalogue('lt');
        $sections = array_unique(array_map(static fn (string $key) => strtok($key, '.'), array_keys($catalogue)));
        $pattern = '/[\'"]((?:'.implode('|', array_map('preg_quote', $sections)).')\.[A-Za-z0-9_.\-]*)[\'"]/';
        $named = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/templates'));
        foreach ([...iterator_to_array($files), ...iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src')))] as $file) {
            if ($file->isFile() && preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches)) {
                foreach ($matches[1] as $key) {
                    $named[$key][] = $file->getFilename();
                }
            }
        }

        self::assertGreaterThan(300, \count($named));
        $missing = [];
        foreach (array_keys($named) as $key) {
            // A prefix the key is built on ('traffic.kind.' ~ kind): some key must start with it.
            $found = str_ends_with($key, '.')
                ? [] !== array_filter(array_keys($catalogue), static fn (string $known) => str_starts_with($known, $key))
                : isset($catalogue[$key]);
            if (!$found) {
                $missing[] = $key.' ('.implode(', ', array_unique($named[$key])).')';
            }
        }
        self::assertSame([], $missing);
    }

    public function testTheJournalsOwnErrorsAreRecognisedAsStored(): void
    {
        self::assertSame(['traffic.error.no_requests', []], ExchangeError::parse(ExchangeError::NO_REQUESTS));
        self::assertSame(['traffic.error.unfinished', []], ExchangeError::parse(ExchangeError::UNFINISHED));
        self::assertSame(['traffic.error.disconnected', ['%reason%' => 'websocket: close 1006']], ExchangeError::parse(ExchangeError::disconnected('websocket: close 1006')));
        self::assertSame(['traffic.error.too_large', ['%size%' => '300']], ExchangeError::parse(ExchangeError::tooLarge(300 * 1_048_576)));
        // As rows from before this class have them.
        self::assertSame(['traffic.error.too_large', ['%size%' => '12']], ExchangeError::parse('Failas per didelis išskaidyti (12 MB) – jis archyve, užklausos skaitomos tik iš jo.'));
        self::assertNull(ExchangeError::parse('This request would exceed your rate limit'), 'an upstream error stays as it came');
    }

    /** @return array<string, string> the catalogue flattened to dotted keys */
    private static function catalogue(string $locale): array
    {
        $flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
            $flat = [];
            foreach ($tree as $key => $value) {
                $flat += \is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key => (string) $value];
            }

            return $flat;
        };

        return $flatten(Yaml::parseFile(self::ROOT."/translations/messages.$locale.yaml"));
    }
}
