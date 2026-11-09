<?php

namespace App\Twig;

use App\Entity\Alert;
use App\Repository\AlertRepository;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Number and time formats of the dashboard (Lithuanian). */
class AppExtension
{
    /** @var array<string, mixed>|null */
    private ?array $status = null;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AlertRepository $alerts,
        private readonly Settings $settings,
    ) {
    }

    /**
     * What every page shows around its content: open alerts, CLIProxyAPI's version and whether the collector runs.
     *
     * @return array{errors: int, warnings: int, version: ?string, latest: ?string, collectorStale: bool, collectorSeen: ?\DateTimeImmutable, collectorError: ?string}
     */
    #[AsTwigFunction('hub_status')]
    public function hubStatus(): array
    {
        if (null !== $this->status) {
            return $this->status;
        }
        $errors = $warnings = 0;
        foreach ($this->alerts->open() as $alert) {
            Alert::ERROR === $alert->level ? ++$errors : (Alert::WARNING === $alert->level ? ++$warnings : null);
        }
        $heartbeat = $this->settings->instant(Settings::COLLECTOR_HEARTBEAT);
        $version = $this->settings->get(Settings::CLIPROXY_VERSION);
        $latest = $this->settings->get(Settings::CLIPROXY_LATEST);
        $error = $this->settings->get(Settings::COLLECTOR_ERROR);

        return $this->status = [
            'errors' => $errors,
            'warnings' => $warnings,
            'version' => \is_string($version) ? $version : null,
            'latest' => \is_string($latest) && \is_string($version) && version_compare(ltrim($latest, 'v'), ltrim($version, 'v'), '>') ? $latest : null,
            // cron starts a pass every minute; three minutes without one means it stopped.
            'collectorStale' => null === $heartbeat || $this->clock->now()->getTimestamp() - $heartbeat->getTimestamp() > 180,
            'collectorSeen' => $heartbeat,
            'collectorError' => \is_string($error) && '' !== $error ? $error : null,
        ];
    }

    /** 1234567 → 1,2 M */
    #[AsTwigFilter('tokens')]
    public function tokens(int|float|null $value): string
    {
        $value = (float) $value;
        foreach ([1e9 => ' mlrd.', 1e6 => ' M', 1e3 => ' k'] as $size => $suffix) {
            if (abs($value) >= $size) {
                return number_format($value / $size, $value / $size >= 100 ? 0 : 1, ',', ' ').$suffix;
            }
        }

        return number_format($value, 0, ',', ' ');
    }

    /** A share in percent, or a dash when there is nothing to divide. */
    #[AsTwigFilter('share')]
    public function share(int|float $part, int|float $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 1, ',', ' ').' %' : '–';
    }

    /** ok below 70 %, warn below 90 %, high from there: the colour of a limit bar. */
    #[AsTwigFilter('level')]
    public function level(?float $used): string
    {
        return match (true) {
            null === $used => 'none',
            $used >= 90 => 'high',
            $used >= 70 => 'warn',
            default => 'ok',
        };
    }

    /** How long until an instant: "po 2 d. 4 val.", "po 35 min." */
    #[AsTwigFilter('until')]
    public function until(\DateTimeInterface|int|null $instant): string
    {
        $instant = \is_int($instant) ? new \DateTimeImmutable('@'.$instant) : $instant;
        if (null === $instant) {
            return '–';
        }
        $seconds = $instant->getTimestamp() - $this->clock->now()->getTimestamp();

        return $seconds <= 0 ? 'dabar' : 'po '.self::span($seconds);
    }

    /** A moment in the coming or past days: "Pr 14:30", or "rytoj 09:00", "šiandien 18:00". */
    #[AsTwigFilter('when')]
    public function when(\DateTimeInterface|int|null $instant): string
    {
        if (null === $instant) {
            return '–';
        }
        $zone = new \DateTimeZone(date_default_timezone_get());
        $at = (\is_int($instant) ? new \DateTimeImmutable('@'.$instant) : \DateTimeImmutable::createFromInterface($instant))->setTimezone($zone);
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $days = (int) floor(($at->setTime(0, 0)->getTimestamp() - $today->getTimestamp()) / 86400 + 0.5);

        return match (true) {
            0 === $days => 'šiandien',
            1 === $days => 'rytoj',
            $days > 1 && $days < 7 => ['Sk', 'Pr', 'An', 'Tr', 'Kt', 'Pn', 'Št'][(int) $at->format('w')],
            default => $at->format('m-d'),
        }.' '.$at->format('H:i');
    }

    /** How long ago: "prieš 3 min." */
    #[AsTwigFilter('ago')]
    public function ago(?\DateTimeInterface $instant): string
    {
        if (null === $instant) {
            return 'niekada';
        }
        $seconds = $this->clock->now()->getTimestamp() - $instant->getTimestamp();

        return $seconds < 60 ? 'ką tik' : 'prieš '.self::span($seconds);
    }

    private static function span(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => $days.' d.'.($hours > 0 ? ' '.$hours.' val.' : ''),
            $hours > 0 => $hours.' val.'.($minutes > 0 ? ' '.$minutes.' min.' : ''),
            default => max(1, $minutes).' min.',
        };
    }
}
