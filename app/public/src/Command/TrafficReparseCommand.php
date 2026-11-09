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
        // The traffic lock for the whole run: the collector skips its traffic steps meanwhile (the logs wait in logs/),
        // while usage records and limits go on being collected (CLIProxyAPI's usage queue keeps them only an hour).
        if (null !== $from && \DateTimeImmutable::createFromFormat('!Y-m-d', $from)?->format('Y-m-d') !== $from) {
            $io->error('--from: YYYY-MM-DD');

            return Command::INVALID;
        }
        // As much as the collector gets (crontab): an archive expands up to 256 MB and is parsed whole.
        ini_set('memory_limit', '2G');
        $lock = $this->lockFactory->createLock(TrafficIngester::LOCK, ttl: 300);
        $lock->acquire(true);
        $done = $failed = 0;
        try {
            foreach ($this->ingester->archived($from) as $path) {
                try {
                    $this->ingester->reparse($path);
                    ++$done;
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->doctrine->resetManager();
                    $io->warning("$path: ".$exception->getMessage());
                }
                $lock->refresh();
            }
            // Rebuilt turns find their usage records again, over the whole range read.
            $this->ingester->linkAccounts(new \DateTimeImmutable(($from ?? '2000-01-01').' 00:00:00'));
        } finally {
            $lock->release();
        }
        $io->writeln(\sprintf('%d archived logs read again, %d failed.', $done, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
