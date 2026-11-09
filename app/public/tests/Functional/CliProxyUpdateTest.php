<?php

namespace App\Tests\Functional;

use App\CliProxy\Releases;
use App\Command\CliProxyUpdateCommand;
use App\Entity\Alert;
use App\Settings\Settings;
use App\Tests\Support\Database;
use App\Tests\Support\FakeCliProxy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:cliproxy-update: a new release's update workflow is started once, and the collector's alert tells how it went. */
class CliProxyUpdateTest extends KernelTestCase
{
    use Database;

    private FakeCliProxy $cliProxy;
    private Settings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-09 17:00:00 Europe/Vilnius'));
        $this->cliProxy = self::getContainer()->get(FakeCliProxy::class);
        $this->settings = self::getContainer()->get(Settings::class);
        self::resetDatabase(self::getContainer()->get(EntityManagerInterface::class));
        // The running version, as the collector reads it from CLIProxyAPI.
        $this->collect();
    }

    protected function tearDown(): void
    {
        Clock::set(new MockClock('now'));
        parent::tearDown();
    }

    public function testANewReleaseStartsTheUpdateOnceItsImageIsOutAndNeverAgain(): void
    {
        $this->cliProxy->release = 'v8.0.62';

        self::assertSame(Command::SUCCESS, $this->update());
        self::assertSame([], $this->dispatches(), 'no image yet');
        self::assertSame(['update_waiting', Alert::INFO], $this->alert());

        $this->cliProxy->imageTags[] = 'v8.0.62';
        $this->update();
        $this->update();
        $this->update();

        self::assertCount(1, $dispatches = $this->dispatches());
        self::assertStringEndsWith('/repos/BigSant/cliproxy/actions/workflows/cliproxy-update.yml/dispatches', $dispatches[0]['url']);
        self::assertSame(['ref' => 'master', 'inputs' => ['version' => 'v8.0.62']], json_decode($dispatches[0]['body'], true));
        self::assertSame('v8.0.62', $this->settings->get(Settings::CLIPROXY_LATEST));
        self::assertSame(['update_requested', Alert::INFO], $this->alert());

        // An hour on, the old version still runs: the person is told once.
        Clock::set(new MockClock('2026-11-09 18:01:00 Europe/Vilnius'));
        $this->update();
        self::assertCount(1, $this->dispatches());
        self::assertSame(['update_stuck', Alert::WARNING], $this->alert());

        // The deploy is done: the collector reads the new version and the alert resolves.
        $this->cliProxy->version = 'v8.0.62';
        $this->collect();
        $this->update();
        self::assertCount(1, $this->dispatches());
        self::assertNull($this->alert());
    }

    public function testAStartGitHubRefusedIsTriedAgain(): void
    {
        $this->cliProxy->release = 'v8.0.62';
        $this->cliProxy->imageTags[] = 'v8.0.62';
        $this->cliProxy->dispatchStatus = 401;

        self::assertSame(Command::FAILURE, $this->update());
        self::assertSame(['update_failed', Alert::WARNING], $this->alert());

        $this->cliProxy->dispatchStatus = 204;
        self::assertSame(Command::SUCCESS, $this->update());
        self::assertCount(2, $this->dispatches());
        self::assertSame(['update_requested', Alert::INFO], $this->alert());
    }

    public function testWithoutATokenOrForANewMajorVersionAPersonIsAsked(): void
    {
        $this->cliProxy->release = 'v9.0.0';
        $this->cliProxy->imageTags[] = 'v9.0.0';
        $this->update();
        self::assertSame(['update_major', Alert::WARNING], $this->alert());

        $this->cliProxy->release = 'v8.0.62';
        $this->cliProxy->imageTags[] = 'v8.0.62';
        $releases = new Releases(self::getContainer()->get('http_client'), '', 'BigSant/cliproxy');
        $command = new CliProxyUpdateCommand($releases, $this->settings, self::getContainer()->get(\Psr\Clock\ClockInterface::class));
        $application = new Application(self::$kernel);
        $application->addCommand($command);
        (new CommandTester($application->find('app:cliproxy-update')))->execute([]);
        $this->collect();

        self::assertSame([], $this->dispatches());
        self::assertSame(['update', Alert::WARNING], $this->alert());
    }

    public function testNothingHappensWhileTheNewestRuns(): void
    {
        self::assertSame(Command::SUCCESS, $this->update());

        self::assertSame([], $this->dispatches());
        self::assertNull($this->alert());
    }

    /** One cron run of the update, then the collector's alerts. */
    private function update(): int
    {
        $status = (new CommandTester((new Application(self::$kernel))->find('app:cliproxy-update')))->execute([]);
        $this->collect();

        return $status;
    }

    private function collect(): void
    {
        (new CommandTester((new Application(self::$kernel))->find('app:collect')))->execute([]);
    }

    /** @return list<array{method: string, url: string, body: string}> */
    private function dispatches(): array
    {
        return array_values(array_filter($this->cliProxy->external, static fn (array $call) => str_ends_with($call['url'], '/dispatches')));
    }

    /** @return array{string, string}|null the open update alert's message key (without "alert.") and level */
    private function alert(): ?array
    {
        $alert = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alert::class)->findOneBy(['alertKey' => 'cliproxy:update', 'resolvedAt' => null]);

        return null === $alert ? null : [substr((string) $alert->messageKey, \strlen('alert.')), $alert->level];
    }
}
