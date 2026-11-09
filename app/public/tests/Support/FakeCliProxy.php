<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * CLIProxyAPI's management API in memory, for tests: the http_client of the test kernel answers from here. Tests set
 * the accounts, the queue and the provider answers, then read what the dashboard changed in $calls.
 */
class FakeCliProxy
{
    /** @var list<array<string, mixed>> credential entries as /credentials returns them */
    public array $credentials = [];
    /** @var list<array<string, mixed>> usage records waiting in the queue */
    public array $queue = [];
    /** @var array<string, array{status_code: int, body: string}> provider answers by URL */
    public array $providerAnswers = [];
    /** @var array<string, mixed> */
    public array $config = ['access' => ['api-keys' => ['key-one']], 'routing' => ['strategy' => 'round-robin', 'session-affinity' => true]];
    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $calls = [];
    public string $loginStatus = 'ok';
    /** CLIProxyAPI not running: every request fails to connect. */
    public bool $down = false;

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->down) {
            return new MockResponse('', ['error' => 'Could not resolve host: cliproxy']);
        }
        $headers = $options['normalized_headers']['authorization'] ?? $options['headers'] ?? [];
        if (!\in_array('Authorization: Bearer test-management-key', (array) $headers, true)) {
            return new MockResponse('{"error":"invalid management key"}', ['http_code' => 401]);
        }
        $parts = parse_url($url);
        $path = substr($parts['path'] ?? '', \strlen('/v8/management'));
        parse_str($parts['query'] ?? '', $query);
        $body = isset($options['body']) && \is_string($options['body']) ? (json_decode($options['body'], true) ?? $options['body']) : null;
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        return match ("$method $path") {
            'GET /credentials' => $this->json(['files' => $this->credentials]),
            'GET /observability/usage/queue' => $this->json($this->take((int) ($query['count'] ?? 1))),
            'POST /requests/api-call' => $this->json($this->providerAnswers[$body['url'] ?? ''] ?? ['status_code' => 500, 'body' => '']),
            'PATCH /credentials/fields' => $this->patchCredential($body),
            'PATCH /credentials/status' => $this->patchCredential(['name' => $body['name'], 'disabled' => $body['disabled']]),
            'GET /oauth/auth-url' => $this->json(['status' => 'ok', 'url' => 'https://claude.ai/oauth/authorize?state=s1', 'state' => 's1']),
            'POST /oauth/callback' => $this->json(['status' => 'ok']),
            'GET /oauth/status' => $this->json(['status' => $this->loginStatus] + ('ok' === $this->loginStatus ? ['auth_id' => 'claude-new.json'] : ['error' => 'Bad request'])),
            'GET /config' => $this->json($this->config),
            'PATCH /config' => $this->patchConfig($body),
            'GET /config.yaml' => new MockResponse("config-version: 8\n"),
            default => new MockResponse('{"error":"not found"}', ['http_code' => 404]),
        };
    }

    /** @return list<array<string, mixed>> */
    private function take(int $count): array
    {
        return array_splice($this->queue, 0, $count);
    }

    /** @param array<string, mixed> $fields */
    private function patchCredential(array $fields): MockResponse
    {
        foreach ($this->credentials as &$credential) {
            if ($credential['name'] === $fields['name']) {
                $credential = $fields + $credential;
            }
        }

        return $this->json(['status' => 'ok']);
    }

    /** @param array<string, mixed> $changes */
    private function patchConfig(array $changes): MockResponse
    {
        $this->config = array_replace_recursive($this->config, $changes);
        if (isset($changes['access']['api-keys'])) {
            $this->config['access']['api-keys'] = $changes['access']['api-keys'];
        }

        return $this->json(['status' => 'ok']);
    }

    private function json(mixed $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /** A credential entry like CLIProxyAPI's. */
    public static function credential(string $provider, string $email, array $extra = []): array
    {
        return $extra + [
            'name' => "$provider-$email.json",
            'auth_index' => "idx-$email",
            'provider' => $provider,
            'email' => $email,
            'status' => 'active',
            'disabled' => false,
            'priority' => 0,
            'success' => 3,
            'failed' => 0,
        ];
    }
}
