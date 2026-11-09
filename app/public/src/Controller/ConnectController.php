<?php

namespace App\Controller;

use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Prijungimas: what Claude Code and Codex need to use the hub instead of their own login. */
class ConnectController extends AbstractController
{
    #[Route('/connect', name: 'app_connect', methods: ['GET'])]
    public function __invoke(
        ManagementClient $client,
        #[Autowire('%env(CLIPROXY_PUBLIC_URL)%')] string $publicUrl,
    ): Response {
        $error = null;
        $keys = [];
        try {
            $keys = $client->apiKeys();
        } catch (ManagementException $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('connect/index.html.twig', [
            'error' => $error,
            'url' => rtrim($publicUrl, '/'),
            'key' => $keys[0] ?? null,
        ]);
    }
}
