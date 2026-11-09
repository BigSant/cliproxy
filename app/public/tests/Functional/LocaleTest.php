<?php

namespace App\Tests\Functional;

use App\Alert\AlertMonitor;
use App\CliProxy\ManagementClient;
use App\Entity\Alert;
use App\Entity\UsageRecord;
use App\Settings\Settings;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use App\Usage\Collector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/** LT | EN: Lithuanian by default, English once picked, remembered in the browser; alerts in either. */
class LocaleTest extends WebTestCase
{
    use Database;

    private KernelBrowser $browser;
    private FakeCliProxy $cliProxy;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->cliProxy = self::getContainer()->get(FakeCliProxy::class);
        self::resetDatabase(self::getContainer()->get(EntityManagerInterface::class));
        self::getContainer()->get(Settings::class)->markNow(Settings::COLLECTOR_HEARTBEAT, new \DateTimeImmutable());
    }

    public function testLithuanianByDefaultAndEnglishOncePicked(): void
    {
        $this->cliProxy->queue = [['timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'provider' => 'claude', 'model' => 'claude-fable-5-1',
            'tokens' => ['input_tokens' => 1200, 'output_tokens' => 50, 'cache_read_tokens' => 9000, 'cache_creation_tokens' => 1000, 'total_tokens' => 11250]]];
        self::getContainer()->get(Collector::class)->collectUsage();
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(UsageRecord::class)->count([]));
        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));

        $this->browser->request('GET', '/usage?range=24h');
        self::assertSelectorExists('html[lang="lt"]');
        self::assertSelectorTextContains('.sidebar nav', 'Apžvalga');
        self::assertSelectorTextContains('.stats', '11,3 k');
        self::assertSelectorTextContains('.lang-switch a.active', 'LT');

        $this->browser->request('GET', '/locale/en', server: ['HTTP_REFERER' => 'http://localhost/usage?range=24h']);
        self::assertResponseRedirects('/usage?range=24h', 302, 'back to the page it was picked on');
        self::assertResponseHasCookie('locale');
        $this->browser->followRedirect();

        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('.sidebar nav', 'Overview');
        self::assertSelectorTextContains('.page-head h1', 'Usage');
        self::assertSelectorTextContains('.stats', '11.3 k');
        self::assertSelectorTextContains('.stats', 'input 1.2 k · output 50');
        self::assertSelectorTextContains('.lang-switch a.active', 'EN');

        // Every page renders in English, and it stays English from page to page.
        foreach (['/' => 'Accounts and limits', '/quota' => 'Quota management', '/accounts' => 'Connect an account', '/traffic' => 'Traffic journal',
            '/traffic/sessions' => 'No sessions', '/traffic/analysis' => 'Traffic analysis', '/settings' => 'Routing', '/connect' => 'is required', '/alerts' => 'All good'] as $page => $text) {
            $this->browser->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            self::assertAnySelectorTextContains('main', $text, $page);
        }

        $this->browser->request('GET', '/locale/lt', server: ['HTTP_REFERER' => 'http://localhost/settings']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('h1', 'Nustatymai');
    }

    public function testFlashesAreInThePickedLanguage(): void
    {
        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
        $this->browser->request('GET', '/locale/en');
        $this->browser->request('GET', '/settings');
        $this->browser->submitForm('Delete');
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'The last key cannot be deleted');
    }

    public function testTheLoginPageHasTheSwitchToo(): void
    {
        $this->browser->request('GET', '/locale/en');
        $this->browser->request('GET', '/login');

        self::assertSelectorTextContains('label', 'Password');
        self::assertSelectorExists('button:contains("Sign in")');
    }

    public function testTheSwitchGoesBackOnlyToThisHub(): void
    {
        foreach (['https://evil.example/usage', 'http://localhost//evil.example/x', 'http://localhost/\\evil.example', 'not a url'] as $referer) {
            $this->browser->request('GET', '/locale/en', server: ['HTTP_REFERER' => $referer]);
            self::assertResponseRedirects('/', 302, $referer);
        }
        $this->browser->request('GET', '/locale/de');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAlertsShowInEitherLanguageAndNtfyGetsTheDefault(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'me@x', ['requires_sign_in' => true])];
        self::getContainer()->get(Settings::class)->set(Settings::NTFY_URL, 'https://ntfy.sh/hub-test');
        $monitor = self::getContainer()->get(AlertMonitor::class);
        $monitor->sync($monitor->accountConditions(self::getContainer()->get(ManagementClient::class)->accounts(), []) + $monitor->diskConditions());
        // An alert from before the keys: only its Lithuanian message.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($legacy = new Alert('legacy', Alert::WARNING, 'Senas įspėjimas', new \DateTimeImmutable('-1 hour')));
        $legacy->resolvedAt = new \DateTimeImmutable();
        $entityManager->flush();

        $alert = $entityManager->getRepository(Alert::class)->findOneBy(['alertKey' => 'signin:claude-me@x.json']);
        self::assertSame('alert.signin', $alert->messageKey);
        self::assertStringContainsString('reikia prisijungti iš naujo', $alert->message, 'stored in the default language');
        self::assertStringContainsString('reikia prisijungti iš naujo', $this->cliProxy->external[0]['body'], 'pushed in the default language');

        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
        $this->browser->request('GET', '/alerts');
        self::assertSelectorTextContains('.alert-row', 'reikia prisijungti iš naujo');
        self::assertSelectorTextContains('.alert-row .tag', 'klaida');

        $this->browser->request('GET', '/locale/en');
        $this->browser->request('GET', '/alerts');
        self::assertSelectorTextContains('.alert-row', 'me@x: needs to sign in again');
        self::assertAnySelectorTextContains('.alert-row', 'Claude: no account has any limit left');
        self::assertSelectorTextContains('.alert-row .tag', 'error');
        self::assertAnySelectorTextContains('.is-resolved', 'Senas įspėjimas');
    }
}
