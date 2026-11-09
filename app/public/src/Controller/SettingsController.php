<?php

namespace App\Controller;

use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nustatymai: CLIProxyAPI's routing (strategy, session affinity, retries), the client keys, the dashboard's own
 * soonest-reset-first switch, and config.yaml itself for everything else.
 */
#[Route('/settings')]
class SettingsController extends AbstractController
{
    public const array STRATEGIES = [
        'round-robin' => 'Round-robin (po lygiai)',
        'fill-first' => 'Fill-first (pirma viena paskyra)',
        'weighted-round-robin' => 'Weighted round-robin (pagal svorį)',
    ];

    public function __construct(
        private readonly ManagementClient $client,
        private readonly Settings $settings,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        $error = null;
        $config = [];
        $yaml = '';
        try {
            $config = $this->client->config();
            $yaml = $this->client->configYaml();
        } catch (ManagementException $exception) {
            $error = $exception->getMessage();
        }
        $routing = $config['routing'] ?? [];

        return $this->render('settings/index.html.twig', [
            'error' => $error,
            'strategies' => self::STRATEGIES,
            'strategy' => (string) ($routing['strategy'] ?? 'round-robin'),
            'affinity' => (bool) ($routing['session-affinity'] ?? false),
            'affinityTtl' => (string) ($routing['session-affinity-ttl'] ?? '1h'),
            'requestRetry' => (int) ($routing['retry']['request-retry'] ?? 3),
            'usageEnabled' => (bool) ($config['observability']['usage']['usage-statistics-enabled'] ?? false),
            'requestLog' => (bool) ($config['observability']['logs']['request-log'] ?? false),
            'archiveDays' => $this->settings->archiveDays(),
            'keys' => array_values(array_map('strval', $config['access']['api-keys'] ?? [])),
            'autoPriority' => $this->settings->autoPriority(),
            'refreshMinutes' => $this->settings->quotaRefreshMinutes(),
            'yaml' => $yaml,
        ]);
    }

    #[Route('/routing', name: 'app_settings_routing', methods: ['POST'])]
    public function routing(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $strategy = (string) $request->request->get('strategy');
        $ttl = trim((string) $request->request->get('affinity_ttl'));
        if (!isset(self::STRATEGIES[$strategy]) || !preg_match('/^\d+(s|m|h)$/', $ttl)) {
            $this->addFlash('error', 'Neteisinga strategija arba TTL (pvz. 1h, 30m).');

            return $this->redirectToRoute('app_settings');
        }

        return $this->attempt(fn () => $this->client->patchConfig([
            'routing' => [
                'strategy' => $strategy,
                'session-affinity' => $request->request->getBoolean('affinity'),
                'session-affinity-ttl' => $ttl,
                'retry' => ['request-retry' => max(0, min(10, $request->request->getInt('request_retry')))],
            ],
            'observability' => ['usage' => ['usage-statistics-enabled' => true]],
        ]), 'Maršrutizavimas išsaugotas.');
    }

    #[Route('/dashboard', name: 'app_settings_dashboard', methods: ['POST'])]
    public function dashboard(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->settings->set(Settings::AUTO_PRIORITY, $request->request->getBoolean('auto_priority'));
        $this->settings->set(Settings::QUOTA_REFRESH_MINUTES, max(1, min(60, $request->request->getInt('refresh_minutes', 5))));
        $this->addFlash('success', 'Hub\'o nustatymai išsaugoti. Prioritetai persiskaičiuos kitą kartą skaitant limitus.');

        return $this->redirectToRoute('app_settings');
    }

    /** Srauto žurnalas: CLIProxyAPI writes every request and answer to a file, which the collector takes in. */
    #[Route('/traffic', name: 'app_settings_traffic', methods: ['POST'])]
    public function traffic(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->settings->set(Settings::ARCHIVE_DAYS, max(1, min(90, $request->request->getInt('archive_days', 14))));

        return $this->attempt(fn () => $this->client->patchConfig(['observability' => ['logs' => [
            'request-log' => $request->request->getBoolean('request_log'),
            'logs-max-total-size-mb' => 2000,
        ]]]), 'Srauto žurnalo nustatymai išsaugoti.');
    }

    #[Route('/keys/add', name: 'app_settings_keys_add', methods: ['POST'])]
    public function addKey(Request $request): RedirectResponse
    {
        $this->checkToken($request);

        return $this->attempt(function () {
            $keys = $this->client->apiKeys();
            $keys[] = 'cpa-'.bin2hex(random_bytes(24));
            $this->client->setApiKeys($keys);
        }, 'Naujas raktas sukurtas.');
    }

    #[Route('/keys/delete', name: 'app_settings_keys_delete', methods: ['POST'])]
    public function deleteKey(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $key = (string) $request->request->get('key');

        return $this->attempt(function () use ($key) {
            $keys = $this->client->apiKeys();
            $remaining = array_values(array_filter($keys, static fn (string $existing) => !hash_equals($existing, $key)));
            if ([] === $remaining) {
                // An empty list turns client authentication off altogether.
                throw new ManagementException('Paskutinio rakto ištrinti negalima: tuščias sąrašas išjungtų autentifikaciją.');
            }
            $this->client->setApiKeys($remaining);
        }, 'Raktas ištrintas.');
    }

    #[Route('/yaml', name: 'app_settings_yaml', methods: ['POST'])]
    public function yaml(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $yaml = str_replace("\r\n", "\n", (string) $request->request->get('yaml'));

        return $this->attempt(fn () => $this->client->putConfigYaml($yaml), 'config.yaml išsaugotas.');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
            $this->addFlash('success', $success);
        } catch (ManagementException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_settings');
    }
}
