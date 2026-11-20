<?php

namespace App\Twig;

use App\CliProxy\Providers;
use App\Entity\Alert;
use App\Quota\QuotaWindow;
use App\Repository\AlertRepository;
use App\Settings\Settings;
use App\Traffic\ExchangeError;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Number and time formats of the dashboard, in the page's language (format.* and time.* in translations/). */
class AppExtension
{
    /** @var array<string, mixed>|null */
    private ?array $status = null;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AlertRepository $alerts,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
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

    /** 1234567 → 1,2 M (lt), 1.2 M (en) */
    #[AsTwigFilter('tokens')]
    public function tokens(int|float|null $value): string
    {
        $value = (float) $value;
        foreach ([1e9 => 'format.billion', 1e6 => 'format.million', 1e3 => 'format.thousand'] as $size => $suffix) {
            if (abs($value) >= $size) {
                return $this->decimal($value / $size, $value / $size >= 100 ? 0 : 1).$this->translator->trans($suffix);
            }
        }

        return $this->decimal($value, 0);
    }

    /** A number with the page's decimal point and thousands separator: 1 234,5 (lt), 1,234.5 (en). */
    #[AsTwigFilter('decimal')]
    public function decimal(int|float|null $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, $this->translator->trans('format.decimal_point'), $this->translator->trans('format.thousands_separator'));
    }

    /** A share in percent, or a dash when there is nothing to divide. */
    #[AsTwigFilter('share')]
    public function share(int|float $part, int|float $whole): string
    {
        return $whole > 0 ? $this->translator->trans('format.percent', ['%value%' => $this->decimal($part / $whole * 100, 1)]) : '–';
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

    /** A provider's name as a person reads it: claude → Claude. */
    #[AsTwigFilter('provider_label')]
    public function providerLabel(string $provider): string
    {
        return Providers::label($provider);
    }

    /** A limit window's name in the page's language; one the translations do not know keeps the label it was read with. */
    #[AsTwigFilter('window_label')]
    public function windowLabel(QuotaWindow $window): string
    {
        $id = 'window.'.$window->key;
        $label = $this->translator->trans($id);

        return $label !== $id ? $label : $window->label;
    }

    /** An alert in the page's language: from its key, or as it was stored when it has none (alerts from before keys). */
    #[AsTwigFilter('alert_message')]
    public function alertMessage(Alert $alert): string
    {
        return null !== $alert->messageKey ? $this->translator->trans($alert->messageKey, $alert->messageParams ?? []) : $alert->message;
    }

    /** An exchange's error: the journal's own texts in the page's language, an upstream one as it came. */
    #[AsTwigFilter('exchange_error')]
    public function exchangeError(?string $error): string
    {
        $known = ExchangeError::parse((string) $error);

        return null !== $known ? $this->translator->trans($known[0], $known[1]) : (string) $error;
    }

    /** How long until an instant: "po 2 d. 4 val.", "in 35 min" */
    #[AsTwigFilter('until')]
    public function until(\DateTimeInterface|int|null $instant): string
    {
        $instant = \is_int($instant) ? new \DateTimeImmutable('@'.$instant) : $instant;
        if (null === $instant) {
            return '–';
        }
        $seconds = $instant->getTimestamp() - $this->clock->now()->getTimestamp();

        return $seconds <= 0 ? $this->translator->trans('time.now') : $this->translator->trans('time.in', ['%span%' => $this->span($seconds)]);
    }

    /** A moment in the coming or past days: "Pr 14:30", or "rytoj 09:00", "today 18:00". */
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
            0 === $days => $this->translator->trans('time.today'),
            1 === $days => $this->translator->trans('time.tomorrow'),
            $days > 1 && $days < 7 => $this->translator->trans('weekday.'.$at->format('w')),
            default => $at->format('m-d'),
        }.' '.$at->format('H:i');
    }

    /** How long ago: "prieš 3 min.", "3 min ago" */
    #[AsTwigFilter('ago')]
    public function ago(?\DateTimeInterface $instant): string
    {
        if (null === $instant) {
            return $this->translator->trans('time.never');
        }
        $seconds = $this->clock->now()->getTimestamp() - $instant->getTimestamp();

        return $seconds < 60 ? $this->translator->trans('time.just_now') : $this->translator->trans('time.ago', ['%span%' => $this->span($seconds)]);
    }

    private function span(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $unit = fn (string $unit, int $count) => $this->translator->trans('time.'.$unit, ['%count%' => $count]);

        return match (true) {
            $days > 0 => $unit('days', $days).($hours > 0 ? ' '.$unit('hours', $hours) : ''),
            $hours > 0 => $unit('hours', $hours).($minutes > 0 ? ' '.$unit('minutes', $minutes) : ''),
            default => $unit('minutes', max(1, $minutes)),
        };
    }
}
