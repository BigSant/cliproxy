<?php

namespace App\Controller;

use App\CliProxy\ManagementClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * setup's deploy health check: answers without a login, names the deployed commit (config/release.txt) and checks
 * what the hub needs to work – the database and CLIProxyAPI. Either one down answers 503, so a deploy that broke
 * them rolls back.
 */
class HealthController
{
    public function __construct(
        #[Autowire('%env(int:HEALTH_RETRY_MICROSECONDS)%')]
        private readonly int $retryMicroseconds = 2_000_000,
        #[Autowire('%env(float:HEALTH_DEADLINE_SECONDS)%')]
        private readonly float $deadlineSeconds = 8,
    ) {
    }

    #[Route('/_health', name: 'app_health', methods: ['GET'])]
    public function __invoke(
        #[Autowire('%kernel.project_dir%/config/release.txt')] string $releaseFile,
        Connection $connection,
        ManagementClient $client,
    ): JsonResponse {
        // The whole answer within the deadline: setup's health request waits 10 s.
        $deadline = microtime(true) + $this->deadlineSeconds;
        $release = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '';
        $checks = [];
        try {
            $connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'down';
        }
        // A deploy that recreates CLIProxyAPI checks this while it starts: tries until the deadline, waits and slow
        // answers together.
        $pause = max(100_000, $this->retryMicroseconds);
        $version = $client->version(max(0.5, min(3, $deadline - microtime(true))));
        while (null === $version && microtime(true) + $pause / 1e6 + 0.5 < $deadline) {
            usleep($pause);
            $version = $client->version(min(3, $deadline - microtime(true)));
        }
        $checks['cliproxy'] = $version ?? 'down';
        $healthy = !\in_array('down', $checks, true);

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'release' => str_starts_with($release, '$Format') ? 'dev' : $release, 'checks' => $checks],
            $healthy ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
