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
    /** @var list<array{method: string, url: string, body: string}> */
    public array $external = [];
    /** What ntfy answers. */
    public int $externalStatus = 200;
    /** The running CLIProxyAPI version, in its response headers. */
    public string $version = 'v8.0.61';
    /** CLIProxyAPI's newest release on GitHub, the image tags on Docker Hub and GitHub's answer to a workflow start. */
    public string $release = 'v8.0.61';
    /** @var list<string> */
    public array $imageTags = ['v8.0.61'];
    public int $dispatchStatus = 204;
    /** @var array<string, string> npm's latest version by package */
    public array $npm = ['@anthropic-ai/claude-code' => '2.1.321', '@openai/codex' => '0.167.0'];
    /** Every management call refused, as with a wrong key (CLIProxyAPI still names its version). */
    public bool $rejectAll = false;
    public bool $verified = true;
    /** The last config.yaml saved through PUT. */
    public ?string $savedYaml = null;

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        if ('cliproxy.test' !== parse_url($url, \PHP_URL_HOST)) {
            // Anything else the hub calls (ntfy pushes, GitHub, Docker Hub) is recorded and answered.
            $this->external[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];
            if (\App\CliProxy\Releases::LATEST_URL === $url) {
                return $this->json(['tag_name' => $this->release]);
            }
            if (str_starts_with($url, \App\CliProxy\Releases::IMAGE_TAG_URL)) {
                return new MockResponse('{}', ['http_code' => \in_array(rawurldecode(substr($url, \strlen(\App\CliProxy\Releases::IMAGE_TAG_URL))), $this->imageTags, true) ? 200 : 404]);
            }
            if (str_starts_with($url, 'https://registry.npmjs.org/')) {
                $package = substr($url, \strlen('https://registry.npmjs.org/'), -\strlen('/latest'));

                return isset($this->npm[$package]) ? $this->json(['name' => $package, 'version' => $this->npm[$package]]) : new MockResponse('{}', ['http_code' => 404]);
            }
            if (str_ends_with($url, '/dispatches')) {
                return new MockResponse(204 === $this->dispatchStatus ? '' : '{"message":"Bad credentials"}', ['http_code' => $this->dispatchStatus]);
            }

            return new MockResponse('{}', ['http_code' => $this->externalStatus]);
        }
        if ($this->down) {
            return new MockResponse('', ['error' => 'Could not resolve host: cliproxy']);
        }
        $headers = $options['normalized_headers']['authorization'] ?? $options['headers'] ?? [];
        if ($this->rejectAll || !\in_array('Authorization: Bearer test-management-key', (array) $headers, true)) {
            return new MockResponse('{"error":"invalid management key"}', ['http_code' => 401, 'response_headers' => ['x-cpa-version' => $this->version]]);
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
            'GET /config' => new MockResponse(json_encode($this->config), ['response_headers' => ['content-type' => 'application/json', 'x-cpa-version' => $this->version]]),
            'PATCH /config' => $this->patchConfig($body),
            'GET /config.yaml' => new MockResponse($this->yaml(), ['response_headers' => ['etag' => '"'.sha1($this->yaml()).'"']]),
            'PUT /config.yaml' => $this->putYaml($options),
            'POST /routing/cooldown/reset' => $this->json(['status' => 'ok']),
            'POST /credentials/verify' => $this->json(['status' => 'ok', 'verified' => $this->verified]),
            default => new MockResponse('{"error":"not found"}', ['http_code' => 404]),
        };
    }

    private function yaml(): string
    {
        return $this->savedYaml ?? "config-version: 8\naccess:\n  api-keys: [\"".implode('", "', $this->config['access']['api-keys'] ?? [])."\"]\n"
            ."observability:\n  usage:\n    usage-statistics-enabled: true\n  logs:\n    request-log: true\n    logs-max-total-size-mb: 0\n    error-logs-max-files: 0\n";
    }

    /** @param array<string, mixed> $options */
    private function putYaml(array $options): MockResponse
    {
        $ifMatch = null;
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (str_starts_with(strtolower((string) $header), 'if-match: ')) {
                $ifMatch = substr((string) $header, 10);
            }
        }
        if (null !== $ifMatch && $ifMatch !== '"'.sha1($this->yaml()).'"') {
            return new MockResponse('{"error":"precondition_failed"}', ['http_code' => 412]);
        }
        $this->savedYaml = (string) $options['body'];

        return $this->json(['status' => 'ok']);
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
