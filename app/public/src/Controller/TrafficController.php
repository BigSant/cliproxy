<?php

namespace App\Controller;

use App\Entity\Exchange;
use App\Repository\ExchangeRepository;
use App\Repository\ToolCallRepository;
use App\Traffic\TrafficIngester;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Žurnalas: every request through the hub with what came in, what the model thought, answered and called, and whether
 * the tools worked. Sessions in order, an analysis of tools and endings, and a JSONL export for analysis elsewhere.
 */
#[Route('/traffic')]
class TrafficController extends AbstractController
{
    private const int PAGE = 50;

    public function __construct(
        private readonly ExchangeRepository $exchanges,
        private readonly ToolCallRepository $toolCalls,
        private readonly ClockInterface $clock,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_traffic', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$range, $since] = $this->range($request);
        $filters = $this->filters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->exchanges->countFiltered($filters, $since);

        return $this->render('traffic/index.html.twig', [
            'range' => $range,
            'ranges' => UsageController::RANGES,
            'filters' => $filters,
            'exchanges' => $this->exchanges->search($filters, $since, self::PAGE, ($page - 1) * self::PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/sessions', name: 'app_traffic_sessions', methods: ['GET'])]
    public function sessions(Request $request): Response
    {
        [$range, $since] = $this->range($request);

        return $this->render('traffic/sessions.html.twig', [
            'range' => $range,
            'ranges' => UsageController::RANGES,
            'sessions' => $this->exchanges->sessions($since, 200),
        ]);
    }

    #[Route('/sessions/{sessionId}', name: 'app_traffic_session', methods: ['GET'], requirements: ['sessionId' => '[A-Za-z0-9_.:-]{1,64}'])]
    public function session(string $sessionId): Response
    {
        $exchanges = $this->exchanges->session($sessionId);
        if ([] === $exchanges) {
            throw $this->createNotFoundException();
        }

        return $this->render('traffic/session.html.twig', ['sessionId' => $sessionId, 'exchanges' => $exchanges]);
    }

    #[Route('/analysis', name: 'app_traffic_analysis', methods: ['GET'])]
    public function analysis(Request $request): Response
    {
        [$range, $since] = $this->range($request);

        return $this->render('traffic/analysis.html.twig', [
            'range' => $range,
            'ranges' => UsageController::RANGES,
            'totals' => $this->exchanges->totals($since),
            'endings' => $this->exchanges->endings($since),
            'tools' => $this->toolCalls->stats($since),
            'failures' => $this->toolCalls->recentFailures($since, 25),
        ]);
    }

    /** Every exchange of the period with its tool calls, one JSON object a line. */
    #[Route('/export.jsonl', name: 'app_traffic_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        [$range, $since] = $this->range($request);
        $filters = $this->filters($request);
        $entityManager = $this->entityManager;
        $response = new StreamedResponse(function () use ($filters, $since, $entityManager) {
            $before = null;
            do {
                $batch = $this->exchanges->exportBatch($filters, $since, $before, 200);
                foreach ($batch as $exchange) {
                    echo json_encode(self::exportRow($exchange), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE), "\n";
                    $before = (int) $exchange->getId();
                }
                flush();
                $entityManager->clear();
            } while (200 === \count($batch));
        });
        $response->headers->set('Content-Type', 'application/x-ndjson; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="cliproxy-%s-%s.jsonl"', $range, $this->clock->now()->format('Ymd-His')));

        return $response;
    }

    #[Route('/{id}', name: 'app_traffic_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Exchange $exchange): Response
    {
        $session = '' !== $exchange->sessionId ? $this->exchanges->session($exchange->sessionId) : [];
        $position = array_search($exchange->getId(), array_map(static fn (Exchange $e) => $e->getId(), $session), true);

        return $this->render('traffic/show.html.twig', [
            'exchange' => $exchange,
            'previous' => false !== $position && $position > 0 ? $session[$position - 1] : null,
            'next' => false !== $position && isset($session[$position + 1]) ? $session[$position + 1] : null,
            'sessionLength' => \count($session),
            'position' => false !== $position ? $position + 1 : null,
        ]);
    }

    /** The raw request log as CLIProxyAPI wrote it, while the archive keeps it. */
    #[Route('/{id}/raw', name: 'app_traffic_raw', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function raw(Exchange $exchange, TrafficIngester $ingester): Response
    {
        $path = $ingester->archiveFile($exchange);
        if (null === $path) {
            throw $this->createNotFoundException('The raw log is no longer in the archive.');
        }
        $response = new StreamedResponse(static function () use ($path) {
            $handle = gzopen($path, 'rb');
            while (!gzeof($handle)) {
                echo gzread($handle, 65536);
            }
            gzclose($handle);
        });
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.str_replace('"', '', $exchange->logFile).'"');

        return $response;
    }

    /** @return array{0: string, 1: \DateTimeImmutable} */
    private function range(Request $request): array
    {
        $range = (string) $request->query->get('range', '24h');
        if (!isset(UsageController::RANGES[$range])) {
            $range = '24h';
        }

        return [$range, $this->clock->now()->modify(UsageController::RANGES[$range][0])];
    }

    /** @return array{provider: string, model: string, account: string, session: string, outcome: string, kind: string, q: string} */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['provider', 'model', 'account', 'session', 'outcome', 'kind', 'q'] as $key) {
            $filters[$key] = mb_substr(trim((string) $request->query->get($key, '')), 0, 190);
        }
        if (!\in_array($filters['outcome'], ExchangeRepository::OUTCOMES, true)) {
            $filters['outcome'] = '';
        }
        if (!\in_array($filters['kind'], ExchangeRepository::KINDS, true)) {
            $filters['kind'] = '';
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private static function exportRow(Exchange $exchange): array
    {
        return [
            'id' => (int) $exchange->getId(),
            'at' => $exchange->occurredAt->format(\DATE_ATOM),
            'provider' => $exchange->provider,
            'model' => $exchange->model,
            'endpoint' => $exchange->endpoint,
            'client' => $exchange->client,
            'session_id' => $exchange->sessionId,
            'kind' => $exchange->kind,
            'account' => $exchange->account,
            'status' => $exchange->statusCode,
            'failed' => $exchange->failed,
            'error' => $exchange->error,
            'stop_reason' => $exchange->stopReason,
            'duration_ms' => $exchange->durationMs,
            'tokens' => [
                'input' => $exchange->inputTokens,
                'output' => $exchange->outputTokens,
                'cache_read' => $exchange->cacheReadTokens,
                'cache_creation' => $exchange->cacheCreationTokens,
                'reasoning' => $exchange->reasoningTokens,
            ],
            'prompt_kind' => $exchange->promptKind,
            'prompt' => $exchange->prompt,
            'thinking' => $exchange->thinking,
            'answer' => $exchange->answer,
            'messages' => $exchange->messageCount,
            'tool_errors_in_input' => $exchange->toolErrorCount,
            'tool_calls' => array_map(static fn ($call) => [
                'id' => $call->callId,
                'name' => $call->name,
                'input' => $call->input,
                'answered' => $call->answered,
                'is_error' => $call->isError,
                'output' => $call->output,
            ], $exchange->getToolCalls()->toArray()),
        ];
    }
}
