<?php

namespace App\Controller;

use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Repository\QuotaSnapshotRepository;
use App\Repository\UsageRecordRepository;
use App\Settings\Settings;
use App\View\AccountRows;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Apžvalga: the accounts with their limits, today's usage, and what the hub is doing right now. */
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ManagementClient $client,
        private readonly QuotaSnapshotRepository $snapshots,
        private readonly UsageRecordRepository $usage,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $error = null;
        $accounts = [];
        try {
            $accounts = $this->client->accounts();
        } catch (ManagementException $exception) {
            $error = $exception->getMessage();
        }
        $since = $this->clock->now()->modify('-24 hours');

        return $this->render('dashboard/index.html.twig', [
            'error' => $error,
            'rows' => AccountRows::build($accounts, $this->snapshots->latest(), $this->clock->now()),
            'totals' => $this->usage->totals($since),
            'affinity' => $this->usage->affinity($since),
            'recent' => $this->usage->recent(15),
            'autoPriority' => $this->settings->autoPriority(),
            'quotaRefreshedAt' => $this->settings->instant(Settings::QUOTA_REFRESHED_AT),
        ]);
    }

    /** The live activity list, polled by the page every few seconds. */
    #[Route('/live', name: 'app_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(array_map(static fn ($record) => [
            'at' => $record->occurredAt->format(\DATE_ATOM),
            'provider' => $record->provider,
            'model' => $record->model,
            'effort' => $record->reasoningEffort,
            'account' => $record->account,
            'input' => $record->inputTokens,
            'output' => $record->outputTokens,
            'cacheRead' => $record->cacheReadTokens,
            'cacheCreation' => $record->cacheCreationTokens,
            'latencyMs' => $record->latencyMs,
            'failed' => $record->failed,
        ], $this->usage->recent(15)), headers: ['Cache-Control' => 'no-store']);
    }
}
