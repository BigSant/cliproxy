<?php

namespace App\Command;

use App\CliProxy\Releases;
use App\Settings\Settings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * cron every ten minutes (app/config/cron/crontab): asks GitHub for CLIProxyAPI's newest release and, once its image is
 * on Docker Hub, starts the hub repository's update workflow for it – once per version, whatever comes of it (README,
 * "CLIProxyAPI atnaujinimas"). Only a start GitHub did not take is tried again. What it found stays in the settings
 * (Settings::CLIPROXY_UPDATE); the collector's alerts tell it.
 */
#[AsCommand(name: 'app:cliproxy-update', description: 'Find a new CLIProxyAPI release and start its update, once per version')]
class CliProxyUpdateCommand
{
    public const string REQUESTED = 'requested';
    /** GitHub did not take the start: the next run tries again. */
    public const string FAILED = 'failed';
    /** The image is not on Docker Hub yet. */
    public const string WAITING = 'waiting';
    /** No token: a person changes CLIPROXY_VERSION. */
    public const string MANUAL = 'manual';
    /** A new major version (config-version, /v8/management) is never taken without a person. */
    public const string MAJOR = 'major';

    public function __construct(
        private readonly Releases $releases,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $latest = $this->releases->latest();
        $this->settings->set(Settings::CLIPROXY_LATEST, $latest);
        $this->settings->markNow(Settings::CLIPROXY_LATEST_CHECKED_AT, $this->clock->now());
        // The running version, as the collector last read it: unknown is no reason to update.
        $running = $this->settings->get(Settings::CLIPROXY_VERSION);
        if (!\is_string($running) || !version_compare(ltrim($latest, 'v'), ltrim($running, 'v'), '>')) {
            $io->writeln(\sprintf('CLIProxyAPI %s runs; the newest is %s.', \is_string($running) ? $running : '?', $latest));

            return Command::SUCCESS;
        }
        $update = (array) ($this->settings->get(Settings::CLIPROXY_UPDATE) ?? []);
        if (($update['version'] ?? null) === $latest && self::REQUESTED === ($update['state'] ?? null)) {
            $io->writeln("The update to $latest was started at {$update['at']}.");

            return Command::SUCCESS;
        }
        $error = null;
        $state = match (true) {
            strtok(ltrim($latest, 'v'), '.') !== strtok(ltrim($running, 'v'), '.') => self::MAJOR,
            !$this->releases->canRequest() => self::MANUAL,
            !$this->releases->imagePublished($latest) => self::WAITING,
            default => null,
        };
        if (null === $state) {
            try {
                $this->releases->requestUpdate($latest);
                $state = self::REQUESTED;
            } catch (\Throwable $exception) {
                $state = self::FAILED;
                $error = mb_substr($exception->getMessage(), 0, 300);
            }
        }
        $this->settings->set(Settings::CLIPROXY_UPDATE, ['version' => $latest, 'state' => $state, 'at' => $this->clock->now()->format(\DATE_ATOM)] + (null !== $error ? ['error' => $error] : []));
        $io->writeln("CLIProxyAPI $running runs, $latest is out: $state".(null !== $error ? " ($error)" : '').'.');

        return self::FAILED === $state ? Command::FAILURE : Command::SUCCESS;
    }
}
