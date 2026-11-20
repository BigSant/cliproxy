<?php

namespace App\Tests\Functional;

use App\CliProxy\ConfigBaseline;
use App\Entity\UsageRecord;
use App\Quota\QuotaFetcher;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use App\Usage\Collector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardTest extends WebTestCase
{
    use Database;

    private KernelBrowser $browser;
    private FakeCliProxy $cliProxy;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // One fake for the whole test: the kernel is not rebooted between requests.
        $this->browser->disableReboot();
        $this->cliProxy = self::getContainer()->get(FakeCliProxy::class);
        self::resetDatabase(self::getContainer()->get(EntityManagerInterface::class));
        // The collector ran a moment ago: no "collector stopped" banner in the way.
        self::getContainer()->get(\App\Settings\Settings::class)->markNow(\App\Settings\Settings::COLLECTOR_HEARTBEAT, new \DateTimeImmutable());
    }

    public function testEveryPageButHealthNeedsTheLogin(): void
    {
        $this->browser->request('GET', '/');
        self::assertResponseRedirects('/login');

        $this->browser->request('GET', '/_health');
        self::assertResponseIsSuccessful();
        self::assertSame('ok', json_decode((string) $this->browser->getResponse()->getContent(), true)['status']);
    }

    public function testTheLoginTakesTheAdminPassword(): void
    {
        $this->browser->request('GET', '/login');
        $this->browser->submitForm('Prisijungti', ['_password' => 'wrong'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $this->browser->followRedirect();
        self::assertSelectorExists('.flash-error');

        $this->browser->submitForm('Prisijungti', ['_password' => 'test-password'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/');
    }

    public function testTheOverviewShowsAccountsLimitsAndUsage(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'me@example.com')];
        $this->cliProxy->providerAnswers[QuotaFetcher::CLAUDE_USAGE_URL] = ['status_code' => 200, 'body' => json_encode([
            'five_hour' => ['utilization' => 93, 'resets_at' => '2030-01-01T00:00:00Z'],
            'seven_day' => ['utilization' => 40, 'resets_at' => '2030-01-03T00:00:00Z'],
            'iguana_necktie' => ['utilization' => 7, 'resets_at' => '2030-01-03T00:00:00Z'],
        ])];
        $this->cliProxy->queue = [['timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'provider' => 'claude', 'model' => 'claude-fable-5-1',
            'auth_index' => 'idx-me@example.com', 'source' => 'me@example.com', 'session_id' => 's1', 'reasoning_effort' => 'xhigh',
            'tokens' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_tokens' => 9000, 'cache_creation_tokens' => 1000, 'total_tokens' => 10150]]];
        $collector = self::getContainer()->get(Collector::class);
        $collector->collectUsage();
        $collector->refreshQuotas();
        $this->logIn();

        $crawler = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.accounts', 'me@example.com');
        self::assertSame(['93%', '40%', '7%'], $crawler->filter('table.accounts .quota-value')->each(fn ($n) => $n->text()));
        self::assertSelectorExists('table.accounts .quota-high');
        self::assertSelectorTextContains('.stats', '10,2 k');
        self::assertSelectorTextContains('table.live', 'claude-fable-5-1');

        $this->browser->request('GET', '/live');
        $live = json_decode((string) $this->browser->getResponse()->getContent(), true)[0];
        self::assertSame(['me@example.com', 'xhigh'], [$live['account'], $live['effort']], 'the refreshed list keeps the effort');
        foreach (['/accounts', '/usage?range=24h', '/settings', '/connect'] as $page) {
            $this->browser->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(UsageRecord::class)->count([]));
    }

    public function testAnAntigravityAccountShowsItsModelFamiliesAndNeverTakesAClaudeModel(): void
    {
        $this->cliProxy->credentials = [
            FakeCliProxy::credential('claude', 'me@example.com'),
            FakeCliProxy::credential('antigravity', 'me@gmail.com', ['project_id' => 'proj-1', 'priority' => 7]),
        ];
        $this->cliProxy->models = [
            'claude-me@example.com.json' => ['claude-sonnet-4-6', 'claude-opus-5-5'],
            'antigravity-me@gmail.com.json' => ['claude-sonnet-4-6', 'claude-opus-4-6-thinking', 'gemini-4-argon-high'],
        ];
        $this->cliProxy->providerAnswers[QuotaFetcher::ANTIGRAVITY_MODELS_URLS[0]] = ['status_code' => 200, 'body' => json_encode(['models' => [
            'gemini-4-argon-high' => ['quotaInfo' => ['remainingFraction' => 0.75, 'resetTime' => '2030-01-01T00:00:00Z']],
            'gemini-3.8-flash-high' => ['quotaInfo' => ['remainingFraction' => 1, 'resetTime' => '2030-01-01T00:00:00Z']],
            'claude-opus-4-6-thinking' => ['quotaInfo' => ['resetTime' => '2030-01-01T00:00:00Z']],
        ]])];
        self::getContainer()->get(\App\Settings\Settings::class)->set(\App\Settings\Settings::AUTO_PRIORITY, true);
        self::getContainer()->get(Collector::class)->refreshQuotas();
        self::getContainer()->get(\App\CliProxy\ConfigBaseline::class)->apply();
        $this->logIn();

        $call = array_values(array_filter($this->cliProxy->calls, static fn ($c) => 'POST /requests/api-call' === $c['method'].' '.$c['path'] && QuotaFetcher::ANTIGRAVITY_MODELS_URLS[0] === $c['body']['url']))[0];
        self::assertSame(['POST', '{"project":"proj-1"}'], [$call['body']['method'], $call['body']['data']]);
        self::assertSame(['claude-sonnet-4-6'], $this->cliProxy->config['oauth']['excluded-models']['antigravity']);
        self::assertSame(array_keys(ConfigBaseline::ANTIGRAVITY_ALIASES), array_column($this->cliProxy->config['oauth']['model-alias']['antigravity'], 'alias'));
        $patched = array_column(array_filter($this->cliProxy->calls, static fn ($c) => 'PATCH /credentials/fields' === $c['method'].' '.$c['path']), 'body');
        self::assertNotSame([], $patched, 'soonest-reset-first ran');
        self::assertSame([], array_filter($patched, static fn ($b) => 'antigravity-me@gmail.com.json' === ($b['name'] ?? null)), 'and left Antigravity alone');

        $crawler = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('table.accounts tbody tr')->reduce(fn ($tr) => str_contains($tr->text(), 'me@gmail.com'));
        self::assertStringContainsString('Antigravity', $row->text());
        self::assertStringContainsString('Gemini Pro', $row->text());
        self::assertSame(['25%', '0%', '100%'], $row->filter('.quota-value')->each(fn ($n) => $n->text()));

        $this->browser->request('GET', '/accounts');
        self::assertSelectorTextContains('.provider-buttons', 'Antigravity');
        $this->browser->request('GET', '/connect');
        self::assertResponseIsSuccessful();
        // The copy buttons hold the whole text, quotes and all.
        self::assertStringContainsString('data-copy="curl -s http://127.0.0.1:8317/v1/models -H &quot;Authorization: Bearer key-one&quot; | jq -r &#039;.data[] | select(.owned_by == &quot;antigravity&quot;) | .id&#039;', (string) $this->browser->getResponse()->getContent());
    }

    public function testFiveMinuteCacheWritesAreFlaggedAndTheFixIsOnTheConnectPage(): void
    {
        $this->cliProxy->queue = [['timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'provider' => 'claude',
            'tokens' => ['cache_creation_tokens' => 1000, 'cache_creation_5m_tokens' => 900, 'cache_creation_1h_tokens' => 100]]];
        self::getContainer()->get(Collector::class)->collectUsage();
        $this->logIn();

        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.stats .stat-warn', 'promptCacheTtl');
        self::assertSelectorTextContains('.stats', '10,0 %');

        $this->browser->request('GET', '/connect');
        self::assertSelectorTextContains('pre.code', '"promptCacheTtl": "1h"');
        self::assertAnySelectorTextContains('.note-box', 'requires_openai_auth = true');
    }

    public function testAnAccountIsSignedInByPastingTheRedirect(): void
    {
        $this->logIn();
        $this->browser->request('GET', '/accounts');
        $this->browser->submitForm('+ Claude paskyra');
        $crawler = $this->browser->followRedirect();
        self::assertSame('https://claude.ai/oauth/authorize?state=s1', $crawler->selectLink('Atidaryti Claude')->attr('href'));

        $this->browser->submitForm('Prijungti', ['redirect_url' => 'http://localhost:54545/callback?code=c&state=s1']);
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.flash-success', 'claude-new.json');
        $callback = array_values(array_filter($this->cliProxy->calls, fn ($c) => '/oauth/callback' === $c['path']))[0];
        self::assertSame(['provider' => 'claude', 'redirect_url' => 'http://localhost:54545/callback?code=c&state=s1'], $callback['body']);
    }

    public function testAccountsAreTakenOutOfRotationAndTheirFieldsSaved(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('codex', 'b@x')];
        $this->logIn();
        $this->browser->request('GET', '/accounts');

        $this->browser->submitForm('Išjungti');
        self::assertTrue($this->cliProxy->credentials[0]['disabled']);

        $this->browser->followRedirect();
        $this->browser->submitForm('Išsaugoti', ['priority' => '7', 'note' => 'darbo', 'websockets' => true]);
        self::assertSame(7, $this->cliProxy->credentials[0]['priority']);
        self::assertSame('darbo', $this->cliProxy->credentials[0]['note']);
        self::assertTrue($this->cliProxy->credentials[0]['websockets']);
    }

    public function testClientKeysAreAddedButTheLastOneStays(): void
    {
        $this->logIn();
        $this->browser->request('GET', '/settings');
        $this->browser->submitForm('Ištrinti');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Paskutinio rakto');
        self::assertSame(['key-one'], $this->cliProxy->config['access']['api-keys']);

        $this->browser->submitForm('+ Naujas raktas');
        self::assertCount(2, $this->cliProxy->config['access']['api-keys']);
        self::assertStringStartsWith('cpa-', $this->cliProxy->config['access']['api-keys'][1]);
    }

    public function testCliProxyDownIsShownInsteadOfAnError(): void
    {
        $this->logIn();
        $this->cliProxy->down = true;

        $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'CLIProxyAPI nepasiekiamas');
    }

    private function logIn(): void
    {
        $this->browser->loginUser(new \Symfony\Component\Security\Core\User\InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
    }
}
