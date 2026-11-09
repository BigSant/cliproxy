<?php

namespace App\Tests\Functional;

use App\Alert\AlertMonitor;
use App\Entity\Alert;
use App\Settings\Settings;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

class AlertsAndHealthTest extends WebTestCase
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

    public function testAnAccountToSignInAgainOpensAnAlertPushedOnceAndResolvedLater(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'me@x', ['requires_sign_in' => true])];
        self::getContainer()->get(Settings::class)->set(Settings::NTFY_URL, 'https://ntfy.sh/hub-test');
        $monitor = self::getContainer()->get(AlertMonitor::class);
        $client = self::getContainer()->get(\App\CliProxy\ManagementClient::class);

        $monitor->sync($monitor->accountConditions($client->accounts(), []));
        $monitor->sync($monitor->accountConditions($client->accounts(), []));

        $alert = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alert::class)->findOneBy(['alertKey' => 'signin:claude-me@x.json']);
        self::assertTrue($alert->isOpen());
        self::assertSame(Alert::ERROR, $alert->level);
        // Two conditions: the account, and Claude with no usable account left. Each pushed once, not every run.
        self::assertCount(2, $this->cliProxy->external);
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alert::class)->findOneBy(['alertKey' => 'pool:claude'])->isOpen());
        self::assertSame('https://ntfy.sh/hub-test', $this->cliProxy->external[0]['url']);

        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
        $this->browser->request('GET', '/alerts');
        self::assertSelectorTextContains('.alert-row', 'reikia prisijungti');
        self::assertSelectorTextContains('.nav-badge', '2');

        $monitor->sync([]);
        self::assertFalse($alert->isOpen());
        self::assertCount(4, $this->cliProxy->external, 'and told when they cleared');
    }

    public function testAConditionThatComesBackIsANewEpisodeAndAFailedPushIsRetried(): void
    {
        self::getContainer()->get(Settings::class)->set(Settings::NTFY_URL, 'https://ntfy.sh/hub-test');
        $monitor = self::getContainer()->get(AlertMonitor::class);
        $condition = ['disk' => ['level' => Alert::WARNING, 'message' => 'Mažai vietos']];

        $this->cliProxy->externalStatus = 500;
        $monitor->sync($condition);
        $this->cliProxy->externalStatus = 200;
        $monitor->sync($condition);
        $monitor->sync([]);
        $monitor->sync($condition);

        $episodes = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alert::class)->findBy(['alertKey' => 'disk'], ['id' => 'ASC']);
        self::assertCount(2, $episodes, 'the first episode stays as it ended');
        self::assertNotNull($episodes[0]->resolvedAt);
        self::assertNotNull($episodes[0]->notifiedAt, 'pushed on the second try');
        self::assertTrue($episodes[1]->isOpen());
    }

    public function testWhileCliProxyIsDownAccountAlertsStayOpen(): void
    {
        $this->cliProxy->credentials = [FakeCliProxy::credential('claude', 'me@x', ['requires_sign_in' => true])];
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('app:collect'));
        $tester->execute([]);
        $this->cliProxy->down = true;
        $tester->execute([]);

        $alerts = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alert::class);
        self::assertTrue($alerts->findOneBy(['alertKey' => 'signin:claude-me@x.json'])->isOpen());
        self::assertTrue($alerts->findOneBy(['alertKey' => 'cliproxy'])->isOpen());
    }

    public function testTheWatchdogPushesWhenTheCollectorStops(): void
    {
        $watchdog = self::getContainer()->get(\App\Alert\Watchdog::class);
        @mkdir('/tmp/cliproxy-test', 0o777, true);
        $watchdog->beat('https://ntfy.sh/hub-test');
        self::assertSame([], $watchdog->check());

        file_put_contents('/tmp/cliproxy-test/.heartbeat', (string) (time() - 600));
        self::assertArrayHasKey('collector', $watchdog->check());
        self::assertSame([], $watchdog->check(), 'told once');
        $watchdog->beat('https://ntfy.sh/hub-test');
        self::assertSame(['collector' => 'resolved'], $watchdog->check());
    }

    public function testHealthChecksTheDatabaseAndCliProxy(): void
    {
        $this->browser->request('GET', '/_health');
        self::assertResponseIsSuccessful();
        self::assertSame(['database' => 'ok', 'cliproxy' => 'v8.0.61'], json_decode((string) $this->browser->getResponse()->getContent(), true)['checks']);

        $this->cliProxy->down = true;
        $this->browser->request('GET', '/_health');
        self::assertResponseStatusCodeSame(503);

        // CLIProxyAPI answering with a wrong management key is not healthy either.
        $this->cliProxy->down = false;
        $this->cliProxy->rejectAll = true;
        $this->browser->request('GET', '/_health');
        self::assertResponseStatusCodeSame(503);
    }

    public function testConfigYamlIsSavedWithItsKeysAndRefusedWhenItChangedMeanwhile(): void
    {
        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
        $crawler = $this->browser->request('GET', '/settings');
        $yaml = $crawler->filter('textarea[name="yaml"]')->text();
        self::assertStringNotContainsString('key-one', $yaml);

        $this->browser->submitForm('Išsaugoti config.yaml', ['yaml' => $yaml."debug: true\n"]);
        self::assertStringContainsString('key-one', (string) $this->cliProxy->savedYaml);

        // The form still holds the old ETag: the file changed since.
        $this->browser->submit($crawler->selectButton('Išsaugoti config.yaml')->form(['yaml' => $yaml]));
        $this->browser->followRedirect();
        self::assertAnySelectorTextContains('.flash-error', 'pasikeitė');
    }
}
