<?php

namespace App\Alert;

use App\CliProxy\Account;
use App\Entity\Alert;
use App\Entity\QuotaSnapshot;
use App\Quota\QuotaWindow;
use App\Repository\AlertRepository;
use App\Settings\Settings;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns what the collector sees into alerts: each condition has a stable key; a new one opens an episode (and a push
 * through ntfy when a topic is set in Nustatymai, retried until ntfy takes it), one no longer seen is resolved.
 * Nothing is deleted.
 *
 * A condition says what it is as a translation key and parameters (alert.* in translations/): pages show it in their
 * language, ntfy and the message column get the default one.
 */
class AlertMonitor
{
    /** Below this share of free space where the traffic is kept, an alert. */
    private const float DISK_FREE_SHARE = 0.10;

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly Settings $settings,
        private readonly ManagerRegistry $doctrine,
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(TRAFFIC_ARCHIVE_DIR)%')]
        private readonly string $archiveDirectory,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * The conditions of one collector run; ids not in $conditions get resolved. A condition without a key (an alert
     * from before the keys, kept while CLIProxyAPI is away) has its message as it is.
     *
     * @param array<string, array{level: string, key?: ?string, params?: ?array<string, string|int>, message?: string}> $conditions
     */
    public function sync(array $conditions): void
    {
        $now = $this->clock->now();
        $entityManager = $this->doctrine->getManager();
        $open = [];
        foreach ($this->alerts->open() as $alert) {
            $open[$alert->alertKey] = $alert;
        }
        foreach ($conditions as $id => $condition) {
            $key = $condition['key'] ?? null;
            $params = $condition['params'] ?? [];
            $message = null !== $key ? $this->translator->trans($key, $params, null, $this->defaultLocale) : (string) ($condition['message'] ?? '');
            $alert = $open[$id] ?? null;
            if (null === $alert) {
                // New, or back after it cleared: a new episode; the earlier ones stay as they ended.
                $alert = new Alert($id, $condition['level'], $message, $now);
                $entityManager->persist($alert);
            }
            $alert->level = $condition['level'];
            $alert->message = mb_substr($message, 0, 500);
            $alert->messageKey = $key;
            $alert->messageParams = null !== $key ? $params : null;
            $alert->lastSeenAt = $now;
            if (null === $alert->notifiedAt && Alert::INFO !== $alert->level && $this->push($alert)) {
                $alert->notifiedAt = $now;
            }
        }
        foreach ($open as $id => $alert) {
            if (!isset($conditions[$id])) {
                $alert->resolvedAt = $now;
                if (null !== $alert->notifiedAt) {
                    $this->push($alert, resolved: true);
                }
            }
        }
        $entityManager->flush();
    }

    /**
     * What the accounts and their limits say.
     *
     * @param list<Account>                $accounts
     * @param array<string, QuotaSnapshot> $snapshots by auth index
     *
     * @return array<string, array{level: string, key: string, params: array<string, string>}>
     */
    public function accountConditions(array $accounts, array $snapshots): array
    {
        $now = $this->clock->now();
        $conditions = [];
        $usable = [];
        foreach ($accounts as $account) {
            if ($account->disabled) {
                continue;
            }
            $usable[$account->provider] ??= 0;
            if ($account->requiresSignIn) {
                $conditions['signin:'.$account->name] = ['level' => Alert::ERROR, 'key' => 'alert.signin', 'params' => ['%account%' => $account->label()]];
                continue;
            }
            if ('error' === $account->status || $account->unavailable) {
                $conditions['account:'.$account->name] = '' !== $account->statusMessage
                    ? ['level' => Alert::WARNING, 'key' => 'alert.account_error_detail', 'params' => ['%account%' => $account->label(), '%detail%' => $account->statusMessage]]
                    : ['level' => Alert::WARNING, 'key' => 'alert.account_error', 'params' => ['%account%' => $account->label()]];
            }
            $snapshot = $snapshots[$account->authIndex] ?? null;
            $exhausted = $account->quotaBlocked;
            foreach ([QuotaWindow::FIVE_HOUR, QuotaWindow::WEEK] as $key) {
                $exhausted = $exhausted || (bool) $snapshot?->window($key)?->isExhausted($now);
            }
            if (!$exhausted) {
                ++$usable[$account->provider];
            }
        }
        foreach ($usable as $provider => $count) {
            if (0 === $count) {
                $conditions['pool:'.$provider] = ['level' => Alert::ERROR, 'key' => 'alert.pool_empty', 'params' => ['%provider%' => 'claude' === $provider ? 'Claude' : ('codex' === $provider ? 'Codex' : $provider)]];
            }
        }

        return $conditions;
    }

    /** @return array<string, array{level: string, key: string, params: array<string, string>}> free space where the traffic is kept */
    public function diskConditions(): array
    {
        $directory = is_dir($this->archiveDirectory) ? $this->archiveDirectory : \dirname($this->archiveDirectory);
        $free = @disk_free_space($directory);
        $total = @disk_total_space($directory);
        if (false === $free || false === $total || $total <= 0 || $free / $total >= self::DISK_FREE_SHARE) {
            return [];
        }

        return ['disk' => ['level' => Alert::WARNING, 'key' => 'alert.disk', 'params' => ['%free%' => \sprintf('%.0f', $free / 1e9), '%share%' => \sprintf('%.0f', $free / $total * 100)]]];
    }

    private function push(Alert $alert, bool $resolved = false): bool
    {
        $topic = $this->settings->get(Settings::NTFY_URL);
        if (!\is_string($topic) || '' === $topic) {
            return false;
        }
        try {
            $status = $this->httpClient->request('POST', $topic, [
                'headers' => [
                    'Title' => $resolved ? $this->translator->trans('alert.ntfy_resolved', [], null, $this->defaultLocale) : 'CLIProxy hub',
                    'Priority' => $resolved ? 'low' : (Alert::ERROR === $alert->level ? 'high' : 'default'),
                    'Tags' => $resolved ? 'white_check_mark' : (Alert::ERROR === $alert->level ? 'rotating_light' : 'warning'),
                ],
                'body' => $alert->message,
                'timeout' => 10,
            ])->getStatusCode();

            // Not delivered unless ntfy took it: the next run tries again.
            return $status >= 200 && $status < 300;
        } catch (\Throwable $exception) {
            $this->logger->warning('ntfy push failed: {error}', ['error' => $exception->getMessage()]);

            return false;
        }
    }
}
