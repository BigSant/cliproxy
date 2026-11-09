<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** setup's deploy health check: answers without a login and names the deployed commit (config/release.txt). */
class HealthController
{
    #[Route('/_health', name: 'app_health', methods: ['GET'])]
    public function __invoke(#[Autowire('%kernel.project_dir%/config/release.txt')] string $releaseFile): JsonResponse
    {
        $release = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '';

        return new JsonResponse(
            ['status' => 'ok', 'release' => str_starts_with($release, '$Format') ? 'dev' : $release],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
