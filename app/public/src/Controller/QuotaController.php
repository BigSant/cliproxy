<?php

namespace App\Controller;

use App\Chart\TimeChart;
use App\CliProxy\Account;
use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Quota\PoolForecast;
use App\Quota\QuotaWindow;
use App\Repository\QuotaSnapshotRepository;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Kvotos: each provider's accounts as one pool, recorded and projected over two weeks, with when it runs empty, the
 * next reset and the recent burn (Theo's quota page, README "Kvotos").
 */
class QuotaController extends AbstractController
{
    public const array PROVIDERS = ['claude' => 'Claude', 'codex' => 'Codex'];
    /** The views and windows with their names' translation keys. */
    public const array VIEWS = ['remaining' => 'quota.view.remaining', 'drain' => 'quota.view.drain', 'accounts' => 'quota.view.accounts'];
    public const array BURN = [1, 3, 6];
    public const array WINDOW_LABELS = [QuotaWindow::WEEK => 'window.7d', QuotaWindow::FIVE_HOUR => 'window.5h', QuotaWindow::WEEK_FABLE => 'window.7d-fable'];
    public const array COLORS = ['claude' => '#e07a55', 'codex' => '#6f9cf7'];
    private const array ACCOUNT_COLORS = ['#e07a55', '#6f9cf7', '#4fc08d', '#d9a83a', '#b07ce8', '#e86a9a', '#3fb8c9', '#a3a3a3'];

    #[Route('/quota', name: 'app_quota', methods: ['GET'])]
    public function __invoke(Request $request, ManagementClient $client, QuotaSnapshotRepository $snapshots, Settings $settings, ClockInterface $clock, TranslatorInterface $translator): Response
    {
        $provider = (string) $request->query->get('provider', 'all');
        $provider = isset(self::PROVIDERS[$provider]) ? $provider : 'all';
        $view = (string) $request->query->get('view', 'remaining');
        $view = isset(self::VIEWS[$view]) ? $view : 'remaining';
        $burn = \in_array($request->query->getInt('burn', 3), self::BURN, true) ? $request->query->getInt('burn', 3) : 3;
        $window = (string) $request->query->get('window', QuotaWindow::WEEK);
        $window = isset(self::WINDOW_LABELS[$window]) ? $window : QuotaWindow::WEEK;
        $shift = max(-8, min(8, $request->query->getInt('shift')));

        $now = $clock->now();
        // Two weeks, three days of them past, as Theo's page; shift moves a week.
        $start = $now->setTime(0, 0)->modify('-3 days')->modify(($shift * 7).' days');
        $from = $start->getTimestamp();
        $to = $start->modify('+14 days')->getTimestamp();

        $error = null;
        $accounts = [];
        try {
            $accounts = array_values(array_filter($client->accounts(), static fn (Account $a) => !$a->disabled));
        } catch (ManagementException $exception) {
            $error = $exception->getMessage();
        }
        // Only what the chart shows: from its start (or the last 6 hours, for the burn) to its end or now.
        $history = $snapshots->history(array_map(static fn (Account $a) => $a->authIndex, $accounts),
            new \DateTimeImmutable('@'.min($from, $now->getTimestamp() - 6 * 3600)), new \DateTimeImmutable('@'.min($to, $now->getTimestamp())));

        $cards = [];
        foreach (self::PROVIDERS as $key => $label) {
            $mine = array_values(array_filter($accounts, static fn (Account $a) => $a->provider === $key));
            if (('all' !== $provider && $key !== $provider) || ('all' === $provider && [] === $mine)) {
                continue;
            }
            $cardWindow = 'codex' === $key && QuotaWindow::WEEK_FABLE === $window ? QuotaWindow::WEEK : $window;
            $forecast = PoolForecast::build($mine, $history, $cardWindow, $now->getTimestamp(), $from, $to, $burn);
            $cards[] = [
                'key' => $key,
                'label' => $label,
                'accounts' => \count($mine),
                'window' => $cardWindow,
                'forecast' => $forecast,
                'chart' => self::chart($forecast, $view, $key, $from, $to, $now->getTimestamp(), $translator),
            ];
        }

        return $this->render('quota/index.html.twig', [
            'error' => $error,
            'cards' => $cards,
            'provider' => $provider,
            'view' => $view,
            'burn' => $burn,
            'window' => $window,
            'shift' => $shift,
            'from' => $start,
            'to' => $start->modify('+13 days'),
            'counts' => array_map(static fn (string $key) => \count(array_filter($accounts, static fn (Account $a) => $a->provider === $key)), array_combine(array_keys(self::PROVIDERS), array_keys(self::PROVIDERS))),
            'total' => \count($accounts),
            'quotaRefreshedAt' => $settings->instant(Settings::QUOTA_REFRESHED_AT),
        ]);
    }

    private static function chart(PoolForecast $forecast, string $view, string $provider, int $from, int $to, int $now, TranslatorInterface $translator): TimeChart
    {
        $color = self::COLORS[$provider];
        $weekdays = array_map(static fn (int $day) => $translator->trans('weekday.'.$day), range(0, 6));
        if ('drain' === $view) {
            $max = max(5.0, ...array_map(static fn (array $p) => $p['v'], $forecast->drain ?: [['v' => 0]]));
            $chart = new TimeChart($from, $to, ceil($max / 5) * 5, '%/h', $now, $weekdays);
            $chart->add('drain', $translator->trans('quota.view.drain'), $forecast->drain, $color, area: true);

            return $chart;
        }
        if ('accounts' === $view) {
            $chart = new TimeChart($from, $to, 100, '%', $now, $weekdays);
            $index = 0;
            foreach ($forecast->accounts as $authIndex => $account) {
                $accountColor = self::ACCOUNT_COLORS[$index++ % \count(self::ACCOUNT_COLORS)];
                $chart->add("r$authIndex", $account['label'], $account['recorded'], $accountColor);
                $chart->add("p$authIndex", $translator->trans('quota.series.account_forecast', ['%account%' => $account['label']]), $account['projected'], $accountColor, dashed: true);
            }

            return $chart;
        }
        $chart = new TimeChart($from, $to, max(100, $forecast->poolMax), '%', $now, $weekdays);
        $chart->add('recorded', $translator->trans('quota.series.recorded'), $forecast->recorded, $color, area: true);
        $chart->add('projected', $translator->trans('quota.series.forecast'), $forecast->projected, $color, dashed: true, area: true);
        foreach ($forecast->resets as $reset) {
            $chart->mark($reset['t'], $reset['v'], '+'.round($reset['gain']).'%');
        }

        return $chart;
    }
}
