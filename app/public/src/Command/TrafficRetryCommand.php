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
    public function __construct(private readonly TrafficIngester $ingester)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'One file; all when left out')] ?string $file = null): int
    {
        $io->writeln(\sprintf('%d files back in logs/.', $this->ingester->retryQuarantined($file)));

        return 0;
    }
}
