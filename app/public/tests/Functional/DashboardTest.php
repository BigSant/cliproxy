<?php

namespace App\Tests\Functional;

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
            'auth_index' => 'idx-me@example.com', 'source' => 'me@example.com', 'session_id' => 's1',
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
        self::assertSame('me@example.com', json_decode((string) $this->browser->getResponse()->getContent(), true)[0]['account']);
        foreach (['/accounts', '/usage?range=24h', '/settings', '/connect'] as $page) {
            $this->browser->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(UsageRecord::class)->count([]));
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
