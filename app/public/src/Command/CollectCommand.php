<?php

namespace App\Command;

use App\Alert\AlertMonitor;
use App\CliProxy\ConfigBaseline;
use App\CliProxy\ManagementClient;
use App\Entity\Alert;
use App\Repository\QuotaSnapshotRepository;
use App\Settings\Settings;
use App\Traffic\TrafficIngester;
use App\Usage\Collector;
use App\Usage\UsageSpool;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * cron runs it every minute with --duration=55 (app/config/cron/crontab): usage and request logs every few seconds,
 * limits on their interval; once a run the configuration baseline, the CLIProxyAPI version and the alerts. Each step
 * fails on its own: an error in one (CLIProxyAPI restarting, the database away) is reported and the others go on.
 * A run that finds another one still going leaves at once.
 */
#[AsCommand(name: 'app:collect', description: 'Move CLIProxyAPI usage and request logs into the database, refresh limits, raise alerts')]
class CollectCommand
{
    private const int UPDATE_STUCK_SECONDS = 3600;

    /** @var array<string, string> errors of this run, by step */
    private array $errors = [];
    private ?string $usageOutcome = null;

    public function __construct(
        private readonly Collector $collector,
        private readonly LockFactory $lockFactory,
        private readonly ManagementClient $client,
        private readonly ConfigBaseline $configBaseline,
        private readonly AlertMonitor $alerts,
        private readonly QuotaSnapshotRepository $snapshots,
        private readonly TrafficIngester $traffic,
        private readonly UsageSpool $spool,
        private readonly Settings $settings,
        private readonly ManagerRegistry $doctrine,
        private readonly ClockInterface $clock,
        private readonly \App\Alert\Watchdog $watchdog,
        private readonly \App\Repository\AlertRepository $alertRepository,
    ) {
    }

    /** The ntfy topic for the watchdog's file; the database may be the thing that is down, so it is kept. */
    private ?string $ntfyUrlCache = null;

    private function ntfyUrl(): ?string
    {
        try {
            $url = $this->settings->get(Settings::NTFY_URL);
            $this->ntfyUrlCache = \is_string($url) ? $url : '';
        } catch (\Throwable) {
        }

        return $this->ntfyUrlCache;
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconds to keep collecting (0: one pass)')] int $duration = 0,
        #[Option(description: 'Seconds between usage passes')] int $interval = 5,
        #[Option(description: 'Read every account\'s limits now')] bool $quotas = false,
    ): int {
        // Five minutes, refreshed after every step: a slow step never lets a second collector in.
        $lock = $this->lockFactory->createLock('app-collect', ttl: 300);
        if (!$lock->acquire()) {
            $io->writeln('Another collector is running.');

            return Command::SUCCESS;
        }
        $until = time() + $duration;
        $step = function (string $name, callable $action) use ($lock) {
            $this->step($name, $action);
            $lock->refresh();
        };
        // Traffic steps run only while no reparse or retry holds the request logs; they wait for the next pass.
        $trafficLock = $this->lockFactory->createLock(TrafficIngester::LOCK, ttl: 300);
        $traffic = function (callable $action) use ($trafficLock) {
            return function () use ($trafficLock, $action) {
                if (!$trafficLock->acquire()) {
                    return;
                }
                try {
                    $action();
                } finally {
                    $trafficLock->release();
                }
            };
        };
        try {
            $step('config', fn () => $this->configBaseline->apply());
            $step('version', fn () => $this->checkVersion());
            $step('quarantine', $traffic(function () use ($io) {
                if (true === $this->settings->get(Settings::QUARANTINE_RETRY_REQUESTED)) {
                    $this->settings->set(Settings::QUARANTINE_RETRY_REQUESTED, false);
                    $io->writeln(\sprintf('%d quarantined logs back in logs/.', $this->traffic->retryQuarantined()));
                }
            }));
            $step('orphans', $traffic(fn () => $this->traffic->collectOrphans()));
            do {
                $step('usage', function () use ($io) {
                    try {
                        $stored = $this->collector->collectUsage();
                    } catch (\Throwable $exception) {
                        // What became of the records when it failed, not after a later pass that went well.
                        $this->usageOutcome = $this->collector->usageOutcome;
                        throw $exception;
                    }
                    if ($stored > 0) {
                        $io->writeln(\sprintf('Stored %d usage records.', $stored));
                    }
                });
                $step('traffic', $traffic(function () use ($io) {
                    $logs = $this->collector->collectTraffic();
                    if ($logs > 0) {
                        $io->writeln(\sprintf('Took in %d request logs.', $logs));
                    }
                }));
                $step('quotas', function () use ($io, &$quotas) {
                    if ($quotas || $this->collector->quotasDue()) {
                        $quotas = false;
                        $io->writeln(\sprintf('Read the limits of %d accounts.', $this->collector->refreshQuotas()));
                    }
                });
                $step('heartbeat', fn () => $this->settings->markNow(Settings::COLLECTOR_HEARTBEAT, $this->clock->now()));
                $this->watchdog->beat($this->ntfyUrl());
                if (time() + $interval > $until) {
                    break;
                }
                sleep($interval);
            } while (true);
            $step('link', $traffic(fn () => $this->collector->linkTraffic()));
            $step('alerts', fn () => $this->raiseAlerts());
        } finally {
            $lock->release();
        }
        foreach ($this->errors as $name => $error) {
            $io->warning("$name: $error");
        }
        $this->step('status', fn () => $this->settings->set(Settings::COLLECTOR_ERROR, [] === $this->errors ? '' : implode(' · ', array_map(
            static fn (string $name, string $error) => "$name: $error", array_keys($this->errors), $this->errors))));

        return [] === $this->errors ? Command::SUCCESS : Command::FAILURE;
    }

    private function step(string $name, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            $this->errors[$name] = mb_substr($exception->getMessage(), 0, 300);
            // A database error closes the entity manager: the next step gets a fresh one.
            $this->doctrine->resetManager();
        }
    }

    /** The running version; the newest release and the update are app:cliproxy-update's. */
    private function checkVersion(): void
    {
        $version = $this->client->version();
        if (null !== $version) {
            $this->settings->set(Settings::CLIPROXY_VERSION, $version);
        }
    }

    private function raiseAlerts(): void
    {
        $conditions = [];
        try {
            $conditions += $this->alerts->accountConditions($this->client->accounts(), $this->snapshots->latest());
        } catch (\App\CliProxy\ManagementException $exception) {
            $conditions['cliproxy'] = ['level' => Alert::ERROR, 'key' => 'alert.cliproxy_down', 'params' => ['%error%' => $exception->getMessage()]];
            // Without CLIProxyAPI the accounts are unknown, not fine: their open alerts stay as they are.
            foreach ($this->alertRepository->open() as $alert) {
                if (preg_match('/^(signin|account|pool):/', $alert->alertKey)) {
                    $conditions[$alert->alertKey] = ['level' => $alert->level, 'key' => $alert->messageKey, 'params' => $alert->messageParams, 'message' => $alert->message];
                }
            }
        }
        // Lost records are counted for good: the alert stays until they are marked seen (Įspėjimai), even when the
        // database is back by now.
        $this->collector->saveLost();
        $lost = (int) $this->settings->get(Settings::USAGE_LOST);
        if ($lost > (int) $this->settings->get(Settings::USAGE_LOST_SEEN)) {
            $conditions['usage:lost'] = ['level' => Alert::ERROR, 'key' => 'alert.usage_lost', 'params' => ['%count%' => $lost - (int) $this->settings->get(Settings::USAGE_LOST_SEEN)]];
        }
        if (isset($this->errors['usage']) && !isset($conditions['cliproxy'])) {
            $conditions['collector:usage'] = ['level' => Alert::WARNING, 'key' => 'alert.usage_failed.'.match ($this->usageOutcome) {
                Collector::IN_SPOOL => 'in_spool',
                Collector::LEFT_IN_QUEUE => 'left_in_queue',
                Collector::LOST => 'lost',
                Collector::HALTED => 'halted',
                default => 'other',
            }, 'params' => ['%error%' => rtrim($this->errors['usage'], '.')]];
        }
        $seen = (array) ($this->settings->get(Settings::USAGE_BROKEN_LINES_SEEN) ?? []);
        $broken = array_filter((array) ($this->settings->get(Settings::USAGE_BROKEN_LINES) ?? []), static fn ($count, $day) => $count > ($seen[$day] ?? 0), \ARRAY_FILTER_USE_BOTH);
        if ([] !== $broken) {
            $conditions['usage:broken'] = ['level' => Alert::WARNING, 'key' => 'alert.usage_broken',
                'params' => ['%days%' => implode(', ', array_map(static fn ($day, $count) => "$day – $count", array_keys($broken), $broken))]];
        }
        if ([] !== $this->spool->pendingDays()) {
            $conditions['usage:pending'] = ['level' => Alert::WARNING, 'key' => 'alert.usage_pending', 'params' => ['%days%' => implode(', ', $this->spool->pendingDays())]];
        }
        if (isset($this->errors['traffic'])) {
            $conditions['collector:traffic'] = ['level' => Alert::WARNING, 'key' => 'alert.traffic_failed', 'params' => ['%error%' => $this->errors['traffic']]];
        }
        $quarantined = $this->traffic->quarantined();
        if ([] !== $quarantined) {
            $conditions['traffic:quarantine'] = ['level' => Alert::WARNING, 'key' => 'alert.quarantine', 'params' => ['%count%' => \count($quarantined)]];
        }
        $conditions += $this->alerts->diskConditions();
        $version = $this->settings->get(Settings::CLIPROXY_VERSION);
        $latest = $this->settings->get(Settings::CLIPROXY_LATEST);
        if (\is_string($version) && \is_string($latest) && version_compare(ltrim($latest, 'v'), ltrim($version, 'v'), '>')) {
            $conditions['cliproxy:update'] = $this->updateCondition($version, $latest);
        }
        $this->alerts->sync($conditions);
    }

    /**
     * A newer release than the running one, as far as app:cliproxy-update took it. Each state is one alert episode's
     * level and text: a person is pushed (ntfy) once, when the update needs them.
     *
     * @return array{level: string, key: string, params: array<string, string>}
     */
    private function updateCondition(string $version, string $latest): array
    {
        $update = (array) ($this->settings->get(Settings::CLIPROXY_UPDATE) ?? []);
        $state = ($update['version'] ?? null) === $latest ? (string) ($update['state'] ?? '') : '';
        $at = \is_string($update['at'] ?? null) ? new \DateTimeImmutable($update['at']) : null;
        $params = ['%latest%' => $latest, '%version%' => $version, '%at%' => $at?->setTimezone($this->clock->now()->getTimezone())->format('Y-m-d H:i') ?? '', '%error%' => (string) ($update['error'] ?? '')];
        // The workflow, the checks and the deploy take some ten minutes; an hour later something stopped them.
        $stuck = null !== $at && $this->clock->now()->getTimestamp() - $at->getTimestamp() > self::UPDATE_STUCK_SECONDS;

        return match ($state) {
            CliProxyUpdateCommand::REQUESTED => $stuck
                ? ['level' => Alert::WARNING, 'key' => 'alert.update_stuck', 'params' => $params]
                : ['level' => Alert::INFO, 'key' => 'alert.update_requested', 'params' => $params],
            CliProxyUpdateCommand::FAILED => ['level' => Alert::WARNING, 'key' => 'alert.update_failed', 'params' => $params],
            CliProxyUpdateCommand::MANUAL => ['level' => Alert::WARNING, 'key' => 'alert.update', 'params' => $params],
            CliProxyUpdateCommand::MAJOR => ['level' => Alert::WARNING, 'key' => 'alert.update_major', 'params' => $params],
            default => ['level' => Alert::INFO, 'key' => 'alert.update_waiting', 'params' => $params],
        };
    }
}
