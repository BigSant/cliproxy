<?php

namespace App\Command;

use App\Traffic\TrafficIngester;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Moves request logs out of the quarantine back into logs/; the collector takes them on its next pass. */
#[AsCommand(name: 'app:traffic:retry', description: 'Give quarantined request logs another try (the next collector pass takes them)')]
class TrafficRetryCommand
{
    public function __construct(
        private readonly TrafficIngester $ingester,
        private readonly \Symfony\Component\Lock\LockFactory $lockFactory,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'One file; all when left out')] ?string $file = null): int
    {
        $lock = $this->lockFactory->createLock(TrafficIngester::LOCK, ttl: 300);
        $lock->acquire(true);
        try {
            $io->writeln(\sprintf('%d files back in logs/.', $this->ingester->retryQuarantined($file)));
        } finally {
            $lock->release();
        }

        return 0;
    }
}
