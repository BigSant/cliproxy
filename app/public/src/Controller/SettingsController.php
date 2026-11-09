<?php

namespace App\Controller;

use App\CliProxy\ClaudeCodeVersion;
use App\CliProxy\ConfigBaseline;
use App\CliProxy\ConfigConflictException;
use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Repository\UsageRecordRepository;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Nustatymai: CLIProxyAPI's routing (strategy, session affinity, retries), the client keys, the dashboard's own
 * soonest-reset-first switch, and config.yaml itself for everything else.
 */
#[Route('/settings')]
class SettingsController extends AbstractController
{
    /** CLIProxyAPI's routing strategies and their names' translation keys. */
    public const array STRATEGIES = [
        'round-robin' => 'settings.strategy.round_robin',
        'fill-first' => 'settings.strategy.fill_first',
        'weighted-round-robin' => 'settings.strategy.weighted',
    ];

    public function __construct(
        private readonly ManagementClient $client,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly UsageRecordRepository $usage,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        $error = null;
        $config = [];
        $yaml = '';
        $etag = null;
        try {
            $config = $this->client->config();
            ['yaml' => $yaml, 'etag' => $etag] = $this->client->configYaml();
            $yaml = self::maskKeys($yaml, array_values(array_map('strval', $config['access']['api-keys'] ?? [])));
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
            'keys' => array_values(array_map('strval', $config['access']['api-keys'] ?? [])),
            'autoPriority' => $this->settings->autoPriority(),
            'refreshMinutes' => $this->settings->quotaRefreshMinutes(),
            'yaml' => $yaml,
            'etag' => $etag,
            'ntfyUrl' => (string) ($this->settings->get(Settings::NTFY_URL) ?? ''),
            'claudeUserAgent' => ClaudeCodeVersion::configured($config),
            'claudeSeen' => $this->usage->newestClaudeCodeVersion(),
            'claudePath' => ClaudeCodeVersion::PATH,
        ]);
    }

    #[Route('/routing', name: 'app_settings_routing', methods: ['POST'])]
    public function routing(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $strategy = (string) $request->request->get('strategy');
        $ttl = trim((string) $request->request->get('affinity_ttl'));
        if (!isset(self::STRATEGIES[$strategy]) || !preg_match('/^\d+(s|m|h)$/', $ttl)) {
            $this->addFlash('error', $this->translator->trans('settings.flash.routing_invalid'));

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
        ]), 'settings.flash.routing_saved');
    }

    #[Route('/dashboard', name: 'app_settings_dashboard', methods: ['POST'])]
    public function dashboard(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->settings->set(Settings::AUTO_PRIORITY, $request->request->getBoolean('auto_priority'));
        $this->settings->set(Settings::QUOTA_REFRESH_MINUTES, max(1, min(60, $request->request->getInt('refresh_minutes', 5))));
        $this->addFlash('success', $this->translator->trans('settings.flash.dashboard_saved'));

        return $this->redirectToRoute('app_settings');
    }

    /** Srauto žurnalas: CLIProxyAPI writes every request and answer to a file, which the collector takes in. */
    #[Route('/traffic', name: 'app_settings_traffic', methods: ['POST'])]
    public function traffic(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $enabled = $request->request->getBoolean('request_log');
        $this->settings->set(Settings::REQUEST_LOG, $enabled);

        return $this->attempt(fn () => $this->client->patchConfig(['observability' => ['logs' => [
            'request-log' => $enabled,
            // 0: CLIProxyAPI's cleaner never deletes log files; the collector moves them into the archive.
            'logs-max-total-size-mb' => 0,
        ]]]), 'settings.flash.traffic_saved');
    }

    #[Route('/keys/add', name: 'app_settings_keys_add', methods: ['POST'])]
    public function addKey(Request $request): RedirectResponse
    {
        $this->checkToken($request);

        return $this->attempt(function () {
            $keys = $this->client->apiKeys();
            $keys[] = 'cpa-'.bin2hex(random_bytes(24));
            $this->client->setApiKeys($keys);
        }, 'settings.flash.key_added');
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
                throw new ManagementException($this->translator->trans('settings.flash.last_key'));
            }
            $this->client->setApiKeys($remaining);
        }, 'settings.flash.key_deleted');
    }

    #[Route('/yaml', name: 'app_settings_yaml', methods: ['POST'])]
    public function yaml(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $yaml = str_replace("\r\n", "\n", (string) $request->request->get('yaml'));
        $etag = (string) $request->request->get('etag') ?: null;

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $exception) {
            $this->addFlash('error', $this->translator->trans('settings.flash.yaml_invalid', ['%error%' => $exception->getMessage()]));

            return $this->redirectToRoute('app_settings');
        }
        $violations = ConfigBaseline::policyViolations(\is_array($parsed) ? $parsed : [], false !== $this->settings->get(Settings::REQUEST_LOG));
        if ([] !== $violations) {
            $this->addFlash('error', $this->translator->trans('settings.flash.yaml_policy', [
                '%violations%' => implode('; ', array_map(fn (string $violation) => $this->translator->trans($violation), $violations)),
            ]));

            return $this->redirectToRoute('app_settings');
        }

        return $this->attempt(function () use ($yaml, $etag) {
            $keys = $this->client->apiKeys();
            $this->client->putConfigYaml(self::unmaskKeys($yaml, $keys), $etag);
        }, 'settings.flash.yaml_saved');
    }

    #[Route('/ntfy', name: 'app_settings_ntfy', methods: ['POST'])]
    public function ntfy(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $url = trim((string) $request->request->get('ntfy_url'));
        if ('' !== $url && !preg_match('#^https?://[^\s/]+/[A-Za-z0-9_-]{1,64}$#', $url)) {
            $this->addFlash('error', $this->translator->trans('settings.flash.ntfy_invalid'));

            return $this->redirectToRoute('app_settings');
        }
        $this->settings->set(Settings::NTFY_URL, $url);
        $this->addFlash('success', '' === $url ? $this->translator->trans('settings.flash.ntfy_off') : $this->translator->trans('settings.flash.ntfy_on', ['%url%' => $url]));

        return $this->redirectToRoute('app_settings');
    }

    /**
     * The client keys in config.yaml show as placeholders; a save puts the real ones back. A key typed in by hand stays
     * as typed.
     *
     * @param list<string> $keys
     */
    public static function maskKeys(string $yaml, array $keys): string
    {
        foreach ($keys as $index => $key) {
            if ('' !== $key) {
                $yaml = str_replace($key, '<raktas-'.($index + 1).'>', $yaml);
            }
        }

        return $yaml;
    }

    /** @param list<string> $keys */
    public static function unmaskKeys(string $yaml, array $keys): string
    {
        return preg_replace_callback('/<raktas-(\d+)>/', static fn (array $match) => $keys[(int) $match[1] - 1] ?? $match[0], $yaml) ?? $yaml;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }

    /** @param string $success the translation key of the flash when it worked */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
            $this->addFlash('success', $this->translator->trans($success));
        } catch (ConfigConflictException) {
            $this->addFlash('error', $this->translator->trans('settings.flash.yaml_conflict'));
        } catch (ManagementException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_settings');
    }
}
