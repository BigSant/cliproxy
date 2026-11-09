<?php

namespace App\Command;

use App\Usage\Collector;
use App\Usage\UsageSpool;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Imports usage records from the spool again; records the database already has are skipped (their key). */
#[AsCommand(name: 'app:usage:replay', description: 'Import usage records from the spool again (skips those already stored)')]
class UsageReplayCommand
{
    public function __construct(
        private readonly Collector $collector,
        private readonly UsageSpool $spool,
        private readonly \Symfony\Component\Lock\LockFactory $lockFactory,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'A day (YYYY-MM-DD, UTC)')] ?string $day = null,
        #[Option(description: 'Every day in the spool')] bool $all = false,
    ): int {
        $days = $all ? $this->spool->days() : array_filter([$day]);
        if ([] === $days) {
            $io->error('Give a day or --all.');

            return Command::INVALID;
        }
        // The collector's lock: a replay and a pass never store the same records at once.
        $lock = $this->lockFactory->createLock('app-collect', ttl: 600);
        $lock->acquire(true);
        try {
            foreach ($days as $one) {
                $io->writeln(\sprintf('%s: %d records added.', $one, $this->collector->replay($one)));
                $lock->refresh();
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
