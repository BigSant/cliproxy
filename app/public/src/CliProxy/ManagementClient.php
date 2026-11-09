<?php

namespace App\CliProxy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CLIProxyAPI's management API (v8, /v8/management). The hub's only way to read and change the proxy: accounts
 * (credentials), OAuth logins, configuration, client keys and the usage queue. Every call sends the management key;
 * five wrong keys from one address ban it for 30 minutes, so the key is never left out.
 */
class ManagementClient
{
    private const PREFIX = '/v8/management';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(CLIPROXY_MANAGEMENT_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(CLIPROXY_MANAGEMENT_KEY)%')]
        private readonly string $managementKey,
    ) {
    }

    /** @return list<Account> */
    public function accounts(): array
    {
        $files = $this->json('GET', '/credentials')['files'] ?? [];

        return array_values(array_map(Account::fromArray(...), array_filter($files, 'is_array')));
    }

    public function account(string $name): ?Account
    {
        foreach ($this->accounts() as $account) {
            if ($account->name === $name) {
                return $account;
            }
        }

        return null;
    }

    public function setDisabled(string $name, bool $disabled): void
    {
        $this->json('PATCH', '/credentials/status', ['name' => $name, 'disabled' => $disabled]);
    }

    /** @param array<string, mixed> $fields priority, weight, note, websockets, ... */
    public function updateFields(string $name, array $fields): void
    {
        $this->json('PATCH', '/credentials/fields', ['name' => $name] + $fields);
    }

    public function refresh(string $name): void
    {
        $this->json('POST', '/credentials/refresh', ['name' => $name]);
    }

    /** @return array<string, mixed> */
    public function verify(string $name): array
    {
        return $this->json('POST', '/credentials/verify', ['name' => $name]);
    }

    public function delete(string $name): void
    {
        $this->json('DELETE', '/credentials', ['name' => $name]);
    }

    /**
     * Starts a headless OAuth login: the person opens the URL in their own browser and pastes the failed localhost
     * redirect back (submitCallback). Without is_webui CLIProxyAPI opens no callback port.
     *
     * @return array{url: string, state: string}
     */
    public function startLogin(string $provider): array
    {
        $result = $this->json('GET', '/oauth/auth-url', query: ['provider' => $provider]);
        if (!\is_string($result['url'] ?? null) || !\is_string($result['state'] ?? null)) {
            throw new ManagementException('CLIProxyAPI did not return a login URL');
        }

        return ['url' => $result['url'], 'state' => $result['state']];
    }

    /** @return array{status: string, error?: string, auth_id?: string} wait, ok or error */
    public function loginStatus(string $state): array
    {
        $result = $this->json('GET', '/oauth/status', query: ['state' => $state]);

        return ['status' => (string) ($result['status'] ?? 'error')]
            + (isset($result['error']) ? ['error' => (string) $result['error']] : [])
            + (isset($result['auth_id']) ? ['auth_id' => (string) $result['auth_id']] : []);
    }

    public function submitCallback(string $provider, string $redirectUrl): void
    {
        $this->json('POST', '/oauth/callback', ['provider' => $provider, 'redirect_url' => $redirectUrl]);
    }

    /**
     * A request to a provider made with an account's own token ($TOKEN$ in a header). CLIProxyAPI answers 200
     * whatever the provider said; the provider's status and body come back inside.
     *
     * @param array<string, string> $headers
     *
     * @return array{status_code: int, body: string}
     */
    public function providerCall(string $authIndex, string $method, string $url, array $headers): array
    {
        $result = $this->json('POST', '/requests/api-call', [
            'auth_index' => $authIndex, 'method' => $method, 'url' => $url, 'header' => $headers,
        ], timeout: 10);

        return ['status_code' => (int) ($result['status_code'] ?? 0), 'body' => (string) ($result['body'] ?? '')];
    }

    /**
     * Takes up to $count usage records out of CLIProxyAPI's queue. Taking removes them: the collector is the only
     * reader.
     *
     * @return list<array<string, mixed>>
     */
    public function takeUsage(int $count): array
    {
        $records = $this->json('GET', '/observability/usage/queue', query: ['count' => $count]);

        // CLIProxyAPI hands a record it could not encode as a string: kept as it came, never dropped.
        return array_values(array_map(static fn ($record) => \is_array($record) ? $record : ['_unparsed' => (string) json_encode($record)], $records));
    }

    /** @return array<string, mixed> the configuration in the v8 layout */
    public function config(): array
    {
        return $this->json('GET', '/config');
    }

    /** @param array<string, mixed> $changes deep-merged; lists are replaced */
    public function patchConfig(array $changes): void
    {
        $this->json('PATCH', '/config', $changes);
    }

    /** @return array{yaml: string, etag: ?string} the file and its version, for a save that refuses lost updates */
    public function configYaml(): array
    {
        $response = $this->request('GET', '/config.yaml');
        $this->check($response);

        return ['yaml' => $response->getContent(), 'etag' => $response->getHeaders(false)['etag'][0] ?? null];
    }

    /** With the ETag read before, CLIProxyAPI refuses the save (412) when the file changed in between. */
    public function putConfigYaml(string $yaml, ?string $etag = null): void
    {
        $response = $this->request('PUT', '/config.yaml', body: $yaml, contentType: 'application/yaml', headers: null !== $etag ? ['If-Match' => $etag] : []);
        if (412 === $response->getStatusCode()) {
            throw new ManagementException('config.yaml pasikeitė, kol jį redagavai (kitas langas ar CLIProxyAPI). Atnaujink puslapį ir pakeisk dar kartą.', 412);
        }
        $this->check($response);
    }

    /** @return list<string> */
    public function apiKeys(): array
    {
        $config = $this->config();

        return array_values(array_map('strval', $config['access']['api-keys'] ?? []));
    }

    /** @param list<string> $keys */
    public function setApiKeys(array $keys): void
    {
        $this->patchConfig(['access' => ['api-keys' => array_values($keys)]]);
    }

    /** CLIProxyAPI's own version from its response headers, or null when it does not answer. */
    public function version(): ?string
    {
        try {
            $response = $this->request('GET', '/config', timeout: 3);
            // CLIProxyAPI names its version on a refused request too: only an accepted key counts as working.
            if ($response->getStatusCode() >= 300) {
                return null;
            }
            $headers = $response->getHeaders(false);

            return $headers['x-cpa-version'][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /** The newest CLIProxyAPI release (it asks GitHub; call it seldom). */
    public function latestVersion(): ?string
    {
        $latest = $this->json('GET', '/server/latest-version');

        return \is_string($latest['latest-version'] ?? null) ? $latest['latest-version'] : null;
    }

    /** Re-signs an account in place (same file, priority, note and history), instead of removing and adding it. */
    public function startRelogin(string $provider, string $name): array
    {
        $result = $this->json('GET', '/oauth/auth-url', query: ['provider' => $provider, 'name' => $name]);
        if (!\is_string($result['url'] ?? null) || !\is_string($result['state'] ?? null)) {
            throw new ManagementException('CLIProxyAPI did not return a login URL');
        }

        return ['url' => $result['url'], 'state' => $result['state']];
    }

    /** Ends an account's cooldown in CLIProxyAPI (its own pause after 429s), not the provider's limit. */
    public function resetCooldown(string $authIndex): void
    {
        $this->json('POST', '/routing/cooldown/reset', ['auth_index' => $authIndex]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, scalar>      $query
     *
     * @return array<mixed>
     */
    private function json(string $method, string $path, ?array $json = null, array $query = [], float $timeout = 20): array
    {
        $response = $this->request($method, $path, $json, $query, timeout: $timeout);
        $this->check($response);
        $content = $response->getContent(false);
        if ('' === trim($content)) {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            throw new ManagementException(\sprintf('CLIProxyAPI %s %s: not JSON', $method, $path));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, scalar>      $query
     */
    /** @param array<string, string> $headers */
    private function request(string $method, string $path, ?array $json = null, array $query = [], ?string $body = null, ?string $contentType = null, float $timeout = 20, array $headers = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options = [
            'headers' => ['Authorization' => 'Bearer '.$this->managementKey] + ($contentType ? ['Content-Type' => $contentType] : []) + $headers,
            'query' => $query,
            'timeout' => $timeout,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }
        if (null !== $body) {
            $options['body'] = $body;
        }
        try {
            return $this->httpClient->request($method, rtrim($this->baseUrl, '/').self::PREFIX.$path, $options);
        } catch (ExceptionInterface $exception) {
            throw new ManagementException('CLIProxyAPI is not reachable: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function check(\Symfony\Contracts\HttpClient\ResponseInterface $response): void
    {
        try {
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new ManagementException('CLIProxyAPI is not reachable: '.$exception->getMessage(), 0, $exception);
        }
        if ($status >= 300) {
            $error = json_decode($response->getContent(false), true);
            $message = \is_array($error) ? (string) ($error['error'] ?? $error['message'] ?? '') : '';
            throw new ManagementException(\sprintf('CLIProxyAPI answered %d%s', $status, '' !== $message ? ': '.$message : ''), $status);
        }
    }
}
