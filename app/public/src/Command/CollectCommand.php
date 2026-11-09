<?php

namespace App\Command;

use App\CliProxy\ManagementException;
use App\Usage\Collector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * cron runs it every minute with --duration=55 (app/config/cron/crontab): usage every few seconds, limits on their
 * interval. A run that finds another one still going leaves at once.
 */
#[AsCommand(name: 'app:collect', description: 'Move CLIProxyAPI usage and request logs into the database and refresh account limits')]
class CollectCommand
{
    public function __construct(
        private readonly Collector $collector,
        private readonly LockFactory $lockFactory,
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
        $failed = false;
        try {
            do {
                try {
                    $stored = $this->collector->collectUsage();
                    if ($stored > 0) {
                        $io->writeln(\sprintf('Stored %d usage records.', $stored));
                    }
                    $logs = $this->collector->collectTraffic();
                    if ($logs > 0) {
                        $io->writeln(\sprintf('Took in %d request logs.', $logs));
                    }
                    if ($quotas || $this->collector->quotasDue()) {
                        $quotas = false;
                        $io->writeln(\sprintf('Read the limits of %d accounts.', $this->collector->refreshQuotas()));
                    }
                    $this->collector->pruneIfDue();
                } catch (ManagementException $exception) {
                    // CLIProxyAPI restarting: the queue keeps its records for an hour, the next pass takes them.
                    $io->warning($exception->getMessage());
                    $failed = true;
                }
                $lock->refresh();
                if (time() + $interval > $until) {
                    break;
                }
                sleep($interval);
            } while (true);
        } finally {
            $lock->release();
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
