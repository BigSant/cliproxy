<?php

namespace App\CliProxy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CLIProxyAPI's releases and their way into the hub (README, "CLIProxyAPI atnaujinimas"): the newest release on GitHub,
 * whether its Docker image is out, and the hub repository's update workflow (.github/workflows/cliproxy-update.yml),
 * which takes the version from there: the image tried, a commit to master, the checks and the deploy.
 */
class Releases
{
    public const string LATEST_URL = 'https://api.github.com/repos/router-for-me/CLIProxyAPI/releases/latest';
    public const string IMAGE_TAG_URL = 'https://hub.docker.com/v2/repositories/eceasy/cli-proxy-api/tags/';
    public const string WORKFLOW = 'cliproxy-update.yml';
    /** npm's latest release of a package: {version, ...}. */
    public const string NPM_LATEST_URL = 'https://registry.npmjs.org/%s/latest';
    private const string VERSION = '/^v\d+\.\d+\.\d+$/';
    private const array GITHUB_HEADERS = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'cliproxy-hub'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        /** A fine-grained token for the hub repository with Actions: write only; empty: the update is left to a person. */
        #[Autowire('%env(CLIPROXY_UPDATE_TOKEN)%')]
        private readonly string $token,
        #[Autowire('%env(CLIPROXY_UPDATE_REPOSITORY)%')]
        private readonly string $repository,
    ) {
    }

    public function canRequest(): bool
    {
        return '' !== $this->token && '' !== $this->repository;
    }

    /** The newest release's tag (vX.Y.Z). */
    public function latest(): string
    {
        $release = $this->httpClient->request('GET', self::LATEST_URL, ['headers' => self::GITHUB_HEADERS, 'timeout' => 10])->toArray();
        $tag = $release['tag_name'] ?? null;
        if (!\is_string($tag) || !preg_match(self::VERSION, $tag)) {
            throw new \RuntimeException('GitHub named no CLIProxyAPI release version');
        }

        return $tag;
    }

    /**
     * The newest release of every harness CLIProxyAPI presents (Harnesses), from npm.
     *
     * @return array<string, string> version by harness
     */
    public function harnessVersions(): array
    {
        $versions = [];
        foreach (Harnesses::ALL as $harness => $definition) {
            $release = $this->httpClient->request('GET', \sprintf(self::NPM_LATEST_URL, $definition['package']), ['headers' => ['Accept' => 'application/json'], 'timeout' => 10])->toArray();
            $version = $release['version'] ?? null;
            if (!\is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                throw new \RuntimeException("npm named no release version of {$definition['package']}");
            }
            $versions[$harness] = $version;
        }

        return $versions;
    }

    /** Whether eceasy/cli-proxy-api:<version> is on Docker Hub yet: it is pushed some minutes after the release. */
    public function imagePublished(string $version): bool
    {
        $status = $this->httpClient->request('GET', self::IMAGE_TAG_URL.rawurlencode($version), ['timeout' => 10])->getStatusCode();
        if (404 === $status) {
            return false;
        }
        if (200 !== $status) {
            throw new \RuntimeException("Docker Hub answered $status for the $version image");
        }

        return true;
    }

    /** Starts the update workflow on master for $version (it checks again that the version is newer than the pin). */
    public function requestUpdate(string $version): void
    {
        $url = \sprintf('https://api.github.com/repos/%s/actions/workflows/%s/dispatches', $this->repository, self::WORKFLOW);
        $response = $this->httpClient->request('POST', $url, [
            'headers' => self::GITHUB_HEADERS + ['Authorization' => 'Bearer '.$this->token],
            'json' => ['ref' => 'master', 'inputs' => ['version' => $version]],
            'timeout' => 10,
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(\sprintf('GitHub refused the update workflow (%d): %s', $status, mb_substr($response->getContent(false), 0, 200)));
        }
    }
}
