<?php

namespace App\Command;

use App\Traffic\TrafficIngester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Reads the archived request logs again with today's parser: older exchanges get what was added since (WebSocket
 * turns, clean prompts, kinds). The exchanges are rebuilt in place, tool results and links kept; the archive stays.
 */
#[AsCommand(name: 'app:traffic:reparse', description: 'Rebuild the traffic log from the archive with the current parser')]
class TrafficReparseCommand
{
    public function __construct(
        private readonly TrafficIngester $ingester,
        private readonly LockFactory $lockFactory,
        private readonly \Doctrine\Persistence\ManagerRegistry $doctrine,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'From this day (YYYY-MM-DD) on')] ?string $from = null): int
    {
        // The collector's lock, one file at a time: a reparse and a pass never write the same exchanges at once, and the
        // collector still runs between files (CLIProxyAPI's usage queue keeps its records only an hour).
        $lock = $this->lockFactory->createLock('app-collect', ttl: 300);
        $done = $failed = 0;
        foreach ($this->ingester->archived($from) as $path) {
            $lock->acquire(true);
            try {
                $this->ingester->reparse($path);
                ++$done;
            } catch (\Throwable $exception) {
                ++$failed;
                $this->doctrine->resetManager();
                $io->warning("$path: ".$exception->getMessage());
            } finally {
                $lock->release();
            }
        }
        $io->writeln(\sprintf('%d archived logs read again, %d failed.', $done, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
