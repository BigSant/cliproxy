<?php

namespace App\Controller;

use App\CliProxy\ManagementClient;
use App\CliProxy\ManagementException;
use App\Repository\QuotaSnapshotRepository;
use App\Usage\Collector;
use App\View\AccountRows;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paskyros: sign Claude and Codex accounts in, take them out of rotation, set their routing fields, remove them.
 * A login is CLIProxyAPI's headless OAuth: the person opens the provider's page in their own browser, and pastes
 * back the localhost address it ends on (that page does not load, which is expected); no SSH tunnel is needed.
 */
#[Route('/accounts')]
class AccountController extends AbstractController
{
    public const array PROVIDERS = ['claude' => 'Claude', 'codex' => 'Codex'];
    private const string LOGIN = 'account_login';

    public function __construct(
        private readonly ManagementClient $client,
        private readonly QuotaSnapshotRepository $snapshots,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_accounts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $error = null;
        $accounts = [];
        try {
            $accounts = $this->client->accounts();
        } catch (ManagementException $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('accounts/index.html.twig', [
            'error' => $error,
            'rows' => AccountRows::build($accounts, $this->snapshots->latest(), $this->clock->now()),
            'providers' => self::PROVIDERS,
            'login' => $request->getSession()->get(self::LOGIN),
        ]);
    }

    #[Route('/login', name: 'app_accounts_login_start', methods: ['POST'])]
    public function startLogin(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $provider = (string) $request->request->get('provider');
        if (!isset(self::PROVIDERS[$provider])) {
            throw $this->createNotFoundException();
        }

        return $this->attempt(function () use ($request, $provider) {
            $login = $this->client->startLogin($provider);
            $request->getSession()->set(self::LOGIN, ['provider' => $provider] + $login);
        });
    }

    /** The pasted callback address goes to CLIProxyAPI, which exchanges the code and saves the account. */
    #[Route('/login/callback', name: 'app_accounts_login_callback', methods: ['POST'])]
    public function finishLogin(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $login = $request->getSession()->get(self::LOGIN);
        $redirectUrl = trim((string) $request->request->get('redirect_url'));
        if (!\is_array($login) || '' === $redirectUrl) {
            $this->addFlash('error', 'Įklijuok adresą, kuriuo baigėsi prisijungimas.');

            return $this->redirectToRoute('app_accounts');
        }

        return $this->attempt(function () use ($request, $login, $redirectUrl) {
            $this->client->submitCallback($login['provider'], $redirectUrl);
            // CLIProxyAPI exchanges the code right away; give it a few seconds before telling the person.
            $status = ['status' => 'wait'];
            for ($try = 0; $try < 20 && 'wait' === $status['status']; ++$try) {
                usleep(500_000);
                $status = $this->client->loginStatus($login['state']);
            }
            if ('ok' === $status['status']) {
                $request->getSession()->remove(self::LOGIN);
                $this->addFlash('success', 'Paskyra prijungta'.(isset($status['auth_id']) ? ': '.$status['auth_id'] : '').'. Limitai atsinaujins per kelias minutes.');
            } elseif ('wait' === $status['status']) {
                $this->addFlash('notice', 'CLIProxyAPI dar keičia kodą į raktą. Atnaujink puslapį po kelių sekundžių.');
            } else {
                $request->getSession()->remove(self::LOGIN);
                $this->addFlash('error', 'Prisijungti nepavyko: '.($status['error'] ?? 'nežinoma klaida'));
            }
        }, flash: false);
    }

    #[Route('/login/status', name: 'app_accounts_login_status', methods: ['GET'])]
    public function loginStatus(Request $request): JsonResponse
    {
        $login = $request->getSession()->get(self::LOGIN);
        if (!\is_array($login)) {
            return new JsonResponse(['status' => 'none']);
        }
        try {
            return new JsonResponse($this->client->loginStatus($login['state']));
        } catch (ManagementException $exception) {
            return new JsonResponse(['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }

    #[Route('/login/cancel', name: 'app_accounts_login_cancel', methods: ['POST'])]
    public function cancelLogin(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $request->getSession()->remove(self::LOGIN);

        return $this->redirectToRoute('app_accounts');
    }

    #[Route('/toggle', name: 'app_accounts_toggle', methods: ['POST'])]
    public function toggle(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $name = $this->name($request);
        $disabled = $request->request->getBoolean('disabled');

        return $this->attempt(fn () => $this->client->setDisabled($name, $disabled),
            $disabled ? 'Paskyra išimta iš rotacijos.' : 'Paskyra grąžinta į rotaciją.');
    }

    #[Route('/fields', name: 'app_accounts_fields', methods: ['POST'])]
    public function fields(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $name = $this->name($request);
        $fields = [
            'priority' => $request->request->getInt('priority'),
            'note' => mb_substr(trim((string) $request->request->get('note')), 0, 200),
        ];
        if ($request->request->has('weight')) {
            $fields['weight'] = max(1, min(1_000_000, $request->request->getInt('weight', 1)));
        }
        if ($request->request->has('websockets_field')) {
            $fields['websockets'] = $request->request->getBoolean('websockets');
        }

        return $this->attempt(fn () => $this->client->updateFields($name, $fields), 'Paskyros nustatymai išsaugoti.');
    }

    #[Route('/refresh', name: 'app_accounts_refresh', methods: ['POST'])]
    public function refresh(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $name = $this->name($request);

        return $this->attempt(fn () => $this->client->refresh($name), 'Raktas atnaujintas.');
    }

    #[Route('/verify', name: 'app_accounts_verify', methods: ['POST'])]
    public function verify(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $name = $this->name($request);

        return $this->attempt(function () use ($name) {
            $result = $this->client->verify($name);
            $ok = ($result['ok'] ?? $result['success'] ?? ('ok' === ($result['status'] ?? null))) === true;
            $this->addFlash($ok ? 'success' : 'error', $ok ? 'Paskyra atsako.' : 'Paskyra neatsako: '.json_encode($result, \JSON_UNESCAPED_UNICODE));
        }, flash: false);
    }

    #[Route('/delete', name: 'app_accounts_delete', methods: ['POST'])]
    public function delete(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $name = $this->name($request);

        return $this->attempt(fn () => $this->client->delete($name), 'Paskyra pašalinta iš hub\'o.');
    }

    #[Route('/quotas', name: 'app_accounts_quotas', methods: ['POST'])]
    public function refreshQuotas(Request $request, Collector $collector): RedirectResponse
    {
        $this->checkToken($request);

        return $this->attempt(fn () => $collector->refreshQuotas(), 'Limitai perskaityti.',
            (string) $request->request->get('back') === 'dashboard' ? 'app_dashboard' : 'app_accounts');
    }

    private function name(Request $request): string
    {
        $name = (string) $request->request->get('name');
        if ('' === $name) {
            throw $this->createNotFoundException();
        }

        return $name;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('accounts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }

    private function attempt(callable $action, ?string $success = null, string $route = 'app_accounts', bool $flash = true): RedirectResponse
    {
        try {
            $action();
            if ($flash && null !== $success) {
                $this->addFlash('success', $success);
            }
        } catch (ManagementException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute($route);
    }
}
