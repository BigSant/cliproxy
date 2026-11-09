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
    private const int LATEST_VERSION_SECONDS = 6 * 3600;

    /** @var array<string, string> errors of this run, by step */
    private array $errors = [];

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
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconds to keep collecting (0: one pass)')] int $duration = 0,
        #[Option(description: 'Seconds between usage passes')] int $interval = 5,
        #[Option(description: 'Read every account\'s limits now')] bool $quotas = false,
    ): int {
        $lock = $this->lockFactory->createLock('app-collect', ttl: max(60, $duration + 30));
        if (!$lock->acquire()) {
            $io->writeln('Another collector is running.');

            return Command::SUCCESS;
        }
        $until = time() + $duration;
        try {
            $this->step('config', fn () => $this->configBaseline->apply());
            $this->step('version', fn () => $this->checkVersion());
            do {
                $this->step('usage', function () use ($io) {
                    $stored = $this->collector->collectUsage();
                    if ($stored > 0) {
                        $io->writeln(\sprintf('Stored %d usage records.', $stored));
                    }
                });
                $this->step('traffic', function () use ($io) {
                    $logs = $this->collector->collectTraffic();
                    if ($logs > 0) {
                        $io->writeln(\sprintf('Took in %d request logs.', $logs));
                    }
                });
                $this->step('quotas', function () use ($io, &$quotas) {
                    if ($quotas || $this->collector->quotasDue()) {
                        $quotas = false;
                        $io->writeln(\sprintf('Read the limits of %d accounts.', $this->collector->refreshQuotas()));
                    }
                });
                $this->settings->markNow(Settings::COLLECTOR_HEARTBEAT, $this->clock->now());
                $lock->refresh();
                if (time() + $interval > $until) {
                    break;
                }
                sleep($interval);
            } while (true);
            $this->step('link', fn () => $this->collector->linkTraffic());
            $this->step('alerts', fn () => $this->raiseAlerts());
        } finally {
            $lock->release();
        }
        foreach ($this->errors as $step => $error) {
            $io->warning("$step: $error");
        }
        $this->settings->set(Settings::COLLECTOR_ERROR, [] === $this->errors ? '' : implode(' · ', array_map(
            static fn (string $step, string $error) => "$step: $error", array_keys($this->errors), $this->errors)));

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

    private function checkVersion(): void
    {
        $version = $this->client->version();
        if (null !== $version) {
            $this->settings->set(Settings::CLIPROXY_VERSION, $version);
        }
        $checked = $this->settings->instant(Settings::CLIPROXY_LATEST_CHECKED_AT);
        if (null === $checked || $this->clock->now()->getTimestamp() - $checked->getTimestamp() >= self::LATEST_VERSION_SECONDS) {
            $this->settings->markNow(Settings::CLIPROXY_LATEST_CHECKED_AT, $this->clock->now());
            $latest = $this->client->latestVersion();
            if (null !== $latest) {
                $this->settings->set(Settings::CLIPROXY_LATEST, $latest);
            }
        }
    }

    private function raiseAlerts(): void
    {
        $conditions = [];
        try {
            $conditions += $this->alerts->accountConditions($this->client->accounts(), $this->snapshots->latest());
        } catch (\App\CliProxy\ManagementException $exception) {
            $conditions['cliproxy'] = ['level' => Alert::ERROR, 'message' => 'CLIProxyAPI neatsako: '.$exception->getMessage()];
        }
        if (isset($this->errors['usage']) && !isset($conditions['cliproxy'])) {
            $conditions['collector:usage'] = ['level' => Alert::WARNING, 'message' => 'Usage įrašų nepavyko įrašyti: '.$this->errors['usage'].' (jie saugūs spool\'e ir bus įrašyti vėliau).'];
        }
        if ([] !== $this->spool->pendingDays()) {
            $conditions['usage:pending'] = ['level' => Alert::WARNING, 'message' => 'Usage spool laukia pakartotinio įrašymo: '.implode(', ', $this->spool->pendingDays()).'.'];
        }
        $quarantined = $this->traffic->quarantined();
        if ([] !== $quarantined) {
            $conditions['traffic:quarantine'] = ['level' => Alert::WARNING, 'message' => \sprintf('%d request log\'ų nepavyko perskaityti – jie cliproxy-traffic/quarantine/ su klaida šalia.', \count($quarantined))];
        }
        $conditions += $this->alerts->diskConditions();
        $version = $this->settings->get(Settings::CLIPROXY_VERSION);
        $latest = $this->settings->get(Settings::CLIPROXY_LATEST);
        if (\is_string($version) && \is_string($latest) && version_compare(ltrim($latest, 'v'), ltrim($version, 'v'), '>')) {
            $conditions['cliproxy:update'] = ['level' => Alert::INFO, 'message' => \sprintf('Išleista CLIProxyAPI %s (veikia %s). Atnaujinimas – CLIPROXY_VERSION env/common.env, išbandžius lokaliai.', $latest, $version)];
        }
        $this->alerts->sync($conditions);
    }
}
