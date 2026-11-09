<?php

namespace App\Command;

use App\Alert\Watchdog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/** cron every five minutes, beside the collector: pushes when the collector, the database or CLIProxyAPI stop. */
#[AsCommand(name: 'app:watchdog', description: 'Push to ntfy when the collector, the database or CLIProxyAPI stop')]
class WatchdogCommand
{
    public function __construct(private readonly Watchdog $watchdog)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach ($this->watchdog->check() as $key => $message) {
            $io->writeln("$key: $message");
        }

        return 0;
    }
}
