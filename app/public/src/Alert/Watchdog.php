<?php

namespace App\Alert;

use App\CliProxy\ManagementClient;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The alert that must not depend on what it watches (app:watchdog, its own cron line every five minutes): the
 * collector stopped, the database or CLIProxyAPI not answering. It reads plain files the collector leaves in the
 * traffic directory (.heartbeat, .ntfy – the topic copied from the settings), pushes to ntfy itself and remembers what
 * it told in .watchdog-state. No entity manager, no lock.
 */
class Watchdog
{
    private const int STALE_SECONDS = 300;

    private readonly string $directory;

    public function __construct(
        #[Autowire('%env(TRAFFIC_ARCHIVE_DIR)%')]
        string $archiveDirectory,
        private readonly Connection $connection,
        private readonly ManagementClient $client,
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
    ) {
        $this->directory = \dirname($archiveDirectory);
    }

    /** The collector's side: proof of life and the topic to push to, as files. */
    public function beat(?string $ntfyUrl): void
    {
        foreach (['.heartbeat' => (string) $this->clock->now()->getTimestamp(), '.ntfy' => (string) $ntfyUrl] as $file => $content) {
            if (false !== @file_put_contents($this->directory.'/'.$file, $content)) {
                @chmod($this->directory.'/'.$file, 0o600);
            }
        }
    }

    /** @return array<string, string> the problems now, by key */
    public function problems(): array
    {
        $problems = [];
        $beat = @file_get_contents($this->directory.'/.heartbeat');
        if (false === $beat || $this->clock->now()->getTimestamp() - (int) $beat > self::STALE_SECONDS) {
            $problems['collector'] = 'Kolektorius neveikia: usage, žurnalas ir limitai nesirenka.';
        }
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $problems['database'] = 'Hub\'o duomenų bazė neatsako.';
        }
        if (null === $this->client->version()) {
            $problems['cliproxy'] = 'CLIProxyAPI neatsako – Claude Code ir Codex per hub\'ą neveikia.';
        }

        return $problems;
    }

    /** @return array<string, string> what was pushed now (new problems and cleared ones) */
    public function check(): array
    {
        $statePath = $this->directory.'/.watchdog-state';
        $told = json_decode((string) @file_get_contents($statePath), true);
        $told = \is_array($told) ? $told : [];
        $problems = $this->problems();
        $topic = trim((string) @file_get_contents($this->directory.'/.ntfy'));
        $pushed = [];
        foreach ($problems as $key => $message) {
            if (!isset($told[$key]) && $this->push($topic, $message, true)) {
                $told[$key] = $this->clock->now()->format(\DATE_ATOM);
                $pushed[$key] = $message;
            }
        }
        foreach (array_keys($told) as $key) {
            if (!isset($problems[$key]) && $this->push($topic, 'Vėl veikia: '.$key, false)) {
                unset($told[$key]);
                $pushed[$key] = 'resolved';
            }
        }
        if (false !== @file_put_contents($statePath, json_encode($told))) {
            @chmod($statePath, 0o600);
        }

        return $pushed;
    }

    private function push(string $topic, string $message, bool $problem): bool
    {
        if ('' === $topic) {
            return false;
        }
        try {
            $status = $this->httpClient->request('POST', $topic, [
                'headers' => ['Title' => 'CLIProxy hub', 'Priority' => $problem ? 'urgent' : 'low', 'Tags' => $problem ? 'rotating_light' : 'white_check_mark'],
                'body' => $message,
                'timeout' => 10,
            ])->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }
}
