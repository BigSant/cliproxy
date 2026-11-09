<?php

namespace App\Tests\Functional;

use App\Entity\Exchange;
use App\Entity\ToolCall;
use App\Entity\UsageRecord;
use App\Tests\Support\Database;
use App\Tests\Support\RequestLogs;
use App\Traffic\TrafficIngester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\InMemoryUser;

class TrafficTest extends WebTestCase
{
    use Database;

    private const string LOGS = '/tmp/cliproxy-test/logs';
    private const string ARCHIVE = '/tmp/cliproxy-test/archive';

    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::resetDatabase($this->entityManager);
        foreach ([self::LOGS, self::ARCHIVE, '/tmp/cliproxy-test/quarantine'] as $directory) {
            if (is_dir($directory)) {
                exec('rm -rf '.escapeshellarg($directory));
            }
            mkdir($directory, 0o777, true);
        }
        Clock::set(new MockClock('2026-11-09 15:05:00 Europe/Vilnius'));
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
        parent::tearDown();
    }

    public function testAClaudeTurnIsReadWithThinkingAnswerAndToolCallsAndTheResultMarksTheCall(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        $this->write('v1-messages-2026-11-09T150020-bbbb2222.log', RequestLogs::claudeToolResult());
        $this->entityManager->persist(UsageRecord::fromQueueRecord(['timestamp' => '2026-11-09T13:00:01Z', 'request_id' => 'req-0001-aaaa1111',
            'auth_index' => 'idx-me', 'source' => 'me@example.com', 'session_id' => 's-from-usage', 'provider' => 'claude']));
        $this->entityManager->flush();

        self::assertSame(2, self::getContainer()->get(TrafficIngester::class)->ingest());
        self::getContainer()->get(TrafficIngester::class)->linkAccounts();

        $first = $this->entityManager->getRepository(Exchange::class)->findOneBy(['requestTail' => 'aaaa1111']);
        self::assertSame('claude', $first->provider);
        self::assertSame('claude-fable-5-1', $first->model);
        self::assertSame('user', $first->promptKind);
        self::assertSame('Paleisk testus', $first->prompt);
        self::assertSame('Reikia paleisti testus.', $first->thinking);
        self::assertSame('Paleidžiu testus.', $first->answer);
        self::assertSame('tool_use', $first->stopReason);
        self::assertSame([12, 85, 9000, 300], [$first->inputTokens, $first->outputTokens, $first->cacheReadTokens, $first->cacheCreationTokens]);
        self::assertSame('11111111-2222-3333-4444-555555555555', $first->sessionId);
        self::assertSame('me@example.com', $first->account, 'joined with its usage record');
        self::assertSame('claude-cli/2.1.280 (external, cli)', $first->client);
        self::assertFalse($first->failed);
        $call = $first->getToolCalls()->first();
        self::assertSame(['Bash', '{"command":"bin/phpunit"}'], [$call->name, $call->input]);
        self::assertTrue($call->answered);
        self::assertTrue($call->isError);
        self::assertStringContainsString('Failures: 1', $call->output);

        $second = $this->entityManager->getRepository(Exchange::class)->findOneBy(['requestTail' => 'bbbb2222']);
        self::assertSame('tool_results', $second->promptKind);
        self::assertSame(1, $second->toolErrorCount);
        self::assertSame('Vienas testas krenta.', $second->answer);

        self::assertSame([], glob(self::LOGS.'/v1-*'), 'taken files leave the logs directory');
        self::assertFileExists(self::ARCHIVE.'/2026-11-09/v1-messages-2026-11-09T150000-aaaa1111.log.gz');
        self::assertFileExists(self::LOGS.'/main.log', 'CLIProxyAPI\'s own log stays');
    }

    public function testCodexReasoningCallsAndExitCodes(): void
    {
        $this->write('v1-responses-2026-11-09T150000-cccc3333.log', RequestLogs::codexCall());
        $this->write('v1-responses-2026-11-09T150100-dddd4444.log', RequestLogs::codexResult(2));

        self::getContainer()->get(TrafficIngester::class)->ingest();

        $first = $this->entityManager->getRepository(Exchange::class)->findOneBy(['requestTail' => 'cccc3333']);
        self::assertSame(['codex', 'gpt-5.5-codex', 'completed'], [$first->provider, $first->model, $first->stopReason]);
        self::assertSame('Patikrinsiu failus.', $first->thinking);
        self::assertSame([1000, 4000, 60, 40], [$first->inputTokens, $first->cacheReadTokens, $first->outputTokens, $first->reasoningTokens]);
        self::assertSame('codex-session-1', $first->sessionId);
        $call = $this->entityManager->getRepository(ToolCall::class)->findOneBy(['callId' => 'call_9']);
        self::assertSame('exec_command', $call->name);
        self::assertTrue($call->isError, 'exit code 2');
    }

    public function testACodexWebsocketConnectionBecomesOneExchangePerTurn(): void
    {
        $this->write('v1-responses-2026-11-09T151000-wwww9999.log', RequestLogs::codexWebsocket());
        // The connection's request id (the file name ends with it) on both turns' records; they come as each turn ends.
        foreach ([['13:00:04', 'codex@example.com'], ['13:00:45', 'codex2@example.com']] as [$at, $account]) {
            $this->entityManager->persist(UsageRecord::fromQueueRecord(['timestamp' => "2026-11-09T{$at}Z", 'request_id' => 'conn-wwww9999', 'auth_index' => 'idx-c',
                'source' => $account, 'session_id' => 'ws-session', 'provider' => 'codex', 'latency_ms' => 3500]));
        }
        $this->entityManager->flush();

        self::assertSame(1, self::getContainer()->get(TrafficIngester::class)->ingest());
        self::getContainer()->get(TrafficIngester::class)->linkAccounts();

        $turns = $this->entityManager->getRepository(Exchange::class)->findBy([], ['occurredAt' => 'ASC']);
        self::assertCount(2, $turns);
        [$first, $second] = $turns;
        self::assertSame(['Paleisk testus', 'Paleisiu phpunit.', 'completed', 200, false], [$first->prompt, $first->thinking, $first->stopReason, $first->statusCode, $first->failed]);
        self::assertSame('ws-session', $first->sessionId);
        self::assertSame('codex@example.com', $first->account);
        self::assertSame('codex2@example.com', $second->account, 'each turn its own record, in order');
        self::assertSame(3500, $first->durationMs, 'the timeline\'s own duration, not the record\'s');
        self::assertSame('tool_results', $second->promptKind);
        self::assertSame('Vienas testas krenta.', $second->answer);
        $call = $this->entityManager->getRepository(ToolCall::class)->findOneBy(['callId' => 'call_ws1']);
        self::assertTrue($call->isError, 'exit code 1 in the next turn');
        self::assertStringEndsWith('#1', $first->logFile);
    }

    public function testTakingAFileAgainReplacesItsRowsInsteadOfAdding(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        $copy = RequestLogs::claudeToolUse();
        self::getContainer()->get(TrafficIngester::class)->ingest();
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', $copy);
        self::getContainer()->get(TrafficIngester::class)->ingest();

        self::assertSame(1, $this->entityManager->getRepository(Exchange::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(ToolCall::class)->count([]));
    }

    public function testADiskProblemLeavesTheFileInPlaceForTheNextPass(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        // The archive day cannot be created: a file stands where its directory would be.
        file_put_contents(self::ARCHIVE.'/2026-11-09', 'not a directory');

        try {
            self::getContainer()->get(TrafficIngester::class)->ingest();
            self::fail('a disk problem is reported');
        } catch (\App\Traffic\ArchiveException) {
        }

        self::assertFileExists(self::LOGS.'/v1-messages-2026-11-09T150000-aaaa1111.log', 'not the file\'s fault: it waits');
        self::assertSame([], self::getContainer()->get(TrafficIngester::class)->quarantined());
        unlink(self::ARCHIVE.'/2026-11-09');
        self::assertSame(1, self::getContainer()->get(TrafficIngester::class)->ingest());
    }

    public function testAFileTheProcessDiedOnTwiceGoesToQuarantineAndCanComeBack(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        $this->write('v1-messages-2026-11-09T150020-bbbb2222.log', RequestLogs::claudeToolResult());
        // Two earlier runs died on the first file (memory): the marker says so.
        file_put_contents(self::LOGS.'/.ingesting', json_encode(['file' => 'v1-messages-2026-11-09T150000-aaaa1111.log', 'attempts' => 2]));

        $ingester = self::getContainer()->get(TrafficIngester::class);
        self::assertSame(1, $ingester->ingest(), 'the next file still comes');
        self::assertSame(['v1-messages-2026-11-09T150000-aaaa1111.log'], $ingester->quarantined());
        self::assertStringContainsString('died', (string) file_get_contents('/tmp/cliproxy-test/quarantine/v1-messages-2026-11-09T150000-aaaa1111.log.error'));

        self::assertSame(1, $ingester->retryQuarantined());
        self::assertSame(1, $ingester->ingest());
        self::assertSame([], $ingester->quarantined());
        self::assertSame(2, $this->entityManager->getRepository(Exchange::class)->count([]));
    }

    public function testTakingAFileAgainKeepsTheResultsItsCallsAlreadyGot(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        $this->write('v1-messages-2026-11-09T150020-bbbb2222.log', RequestLogs::claudeToolResult());
        $ingester = self::getContainer()->get(TrafficIngester::class);
        $ingester->ingest();

        // A→B→A: the first file again (from the archive) must not lose the result B gave its call.
        $first = $this->entityManager->getRepository(Exchange::class)->findOneBy(['requestTail' => 'aaaa1111']);
        $ingester->reparse($first->archivePath);

        $call = $this->entityManager->getRepository(ToolCall::class)->findOneBy(['callId' => 'toolu_1']);
        self::assertTrue($call->answered);
        self::assertTrue($call->isError);
        self::assertSame(1, $this->entityManager->getRepository(ToolCall::class)->count([]));
        self::assertSame(['2026-11-09/v1-messages-2026-11-09T150000-aaaa1111.log.gz', '2026-11-09/v1-messages-2026-11-09T150020-bbbb2222.log.gz'], $ingester->archived());
    }

    public function testWebsocketDetails(): void
    {
        $event = static fn (string $at, string $type, array|string $payload) => "Timestamp: $at\nEvent: websocket.$type\n".(\is_string($payload) ? $payload : json_encode($payload))."\n";
        $timeline = implode("\n", [
            // A prewarm: CLIProxyAPI answers it itself.
            $event('2026-11-09T15:00:00.000000000+02:00', 'request', ['type' => 'response.create', 'model' => 'gpt-5.5-codex', 'generate' => false, 'input' => []]),
            $event('2026-11-09T15:00:00.100000000+02:00', 'response', ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]]),
            // A turn ending with response.done, and an interrupt between events.
            $event('2026-11-09T15:00:10.000000000+02:00', 'request', ['type' => 'response.create', 'model' => 'gpt-5.5-codex', 'input' => [['type' => 'message', 'role' => 'user', 'content' => 'Labas']]]),
            $event('2026-11-09T15:00:11.000000000+02:00', 'response', ['type' => 'response.output_text.delta', 'delta' => 'La']),
            $event('2026-11-09T15:00:11.500000000+02:00', 'interrupt.outcome', ['outcome' => 'none']),
            $event('2026-11-09T15:00:12.000000000+02:00', 'response', ['type' => 'response.done', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Labas!']]]]]]),
        ]);
        $this->write('v1-responses-2026-11-09T150000-wsws1234.log', "=== REQUEST INFO ===\nURL: /v1/responses\nMethod: GET\nTimestamp: 2026-11-09T15:00:00.000000000+02:00\n\n=== HEADERS ===\nUser-Agent: codex_cli_rs/0.149.1\n\n=== WEBSOCKET TIMELINE ===\n".$timeline."\n");

        self::getContainer()->get(TrafficIngester::class)->ingest();

        [$prewarm, $turn] = $this->entityManager->getRepository(Exchange::class)->findBy([], ['occurredAt' => 'ASC']);
        self::assertSame('side', $prewarm->kind);
        self::assertSame(['Labas', 'Labas!', 'completed', false, 200], [$turn->prompt, $turn->answer, $turn->stopReason, $turn->failed, $turn->statusCode]);
    }

    public function testSearchFindsShortWordsToo(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        self::getContainer()->get(TrafficIngester::class)->ingest();
        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));

        $this->browser->request('GET', '/traffic?range=all&q=paleisk+te');
        self::assertSelectorCount(1, 'table.traffic tbody tr.row-link');
        $this->browser->request('GET', '/traffic?range=all&q=nera+tokio');
        self::assertSelectorCount(0, 'table.traffic tbody tr.row-link');
    }

    public function testUnfinishedLogPiecesMoveToTheArchiveAfterAnHour(): void
    {
        mkdir(self::LOGS.'/request-log-parts-abc');
        file_put_contents(self::LOGS.'/request-log-parts-abc/part.tmp', 'timeline');
        touch(self::LOGS.'/request-log-parts-abc/part.tmp', strtotime('2026-11-09 13:00:00 Europe/Vilnius'));
        touch(self::LOGS.'/request-log-parts-abc', strtotime('2026-11-09 13:00:00 Europe/Vilnius'));

        self::assertSame(1, self::getContainer()->get(TrafficIngester::class)->collectOrphans());
        self::assertFileExists(self::ARCHIVE.'/orphans/2026-11-09/request-log-parts-abc/part.tmp');
    }

    public function testInjectedRemindersAreNotPartOfThePrompt(): void
    {
        $this->write('v1-messages-2026-11-09T150000-cccc1111.log', RequestLogs::file('/v1/messages', ['model' => 'claude-fable-5-1', 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => "<system-reminder>\nThe date is …\n</system-reminder>"],
            ['type' => 'text', 'text' => 'Sutvarkyk testus'],
        ]]]], '{"type":"message","content":[],"usage":{}}'));

        self::getContainer()->get(TrafficIngester::class)->ingest();

        self::assertSame('Sutvarkyk testus', $this->entityManager->getRepository(Exchange::class)->findOneBy([])->prompt);
    }

    public function testAFailedRequestKeepsItsError(): void
    {
        $this->write('error-v1-messages-2026-11-09T150000-eeee5555.log', RequestLogs::file('/v1/messages', ['model' => 'claude-fable-5-1', 'messages' => [['role' => 'user', 'content' => 'hi']]],
            '{"type":"error","error":{"type":"rate_limit_error","message":"This request would exceed your rate limit"}}', 429));

        self::getContainer()->get(TrafficIngester::class)->ingest();

        $exchange = $this->entityManager->getRepository(Exchange::class)->findOneBy([]);
        self::assertTrue($exchange->failed);
        self::assertSame(429, $exchange->statusCode);
        self::assertSame('This request would exceed your rate limit', $exchange->error);
    }

    public function testAFileStillBeingWrittenWaits(): void
    {
        $this->write('v1-messages-2026-11-09T150459-ffff6666.log', RequestLogs::claudeToolUse(), time());
        Clock::set(new MockClock('now'));

        self::assertSame(0, self::getContainer()->get(TrafficIngester::class)->ingest());
    }

    public function testThePagesShowTheTrafficAndTheExportHasIt(): void
    {
        $this->write('v1-messages-2026-11-09T150000-aaaa1111.log', RequestLogs::claudeToolUse());
        $this->write('v1-messages-2026-11-09T150020-bbbb2222.log', RequestLogs::claudeToolResult());
        self::getContainer()->get(TrafficIngester::class)->ingest();
        $this->browser->loginUser(new InMemoryUser('admin', 'test-password', ['ROLE_ADMIN']));
        $id = $this->entityManager->getRepository(Exchange::class)->findOneBy(['requestTail' => 'aaaa1111'])->getId();

        $this->browser->request('GET', '/traffic?range=90d');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.traffic', 'Paleisk testus');

        $this->browser->request('GET', '/traffic?range=90d&outcome=prompt');
        self::assertSelectorCount(1, 'table.traffic tbody tr');

        $this->browser->request('GET', "/traffic/$id");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.thinking', 'Reikia paleisti testus.');
        self::assertSelectorTextContains('.tool-call', 'klaida');

        $this->browser->request('GET', "/traffic/$id/raw");
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/traffic/sessions/11111111-2222-3333-4444-555555555555');
        self::assertSelectorCount(2, '.timeline li');

        foreach (['/traffic/sessions?range=90d', '/traffic/analysis?range=90d'] as $page) {
            $this->browser->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSelectorTextContains('.card', 'Bash');

        ob_start();
        $this->browser->request('GET', '/traffic/export.jsonl?range=90d');
        $lines = array_filter(explode("\n", (string) ob_get_clean() ?: (string) $this->browser->getInternalResponse()->getContent()));
        self::assertCount(2, $lines);
        $rows = array_map(static fn (string $line) => json_decode($line, true), $lines);
        $first = array_values(array_filter($rows, static fn (array $row) => 'user' === $row['prompt_kind']))[0];
        self::assertSame('Bash', $first['tool_calls'][0]['name']);
        self::assertTrue($first['tool_calls'][0]['is_error']);
    }

    private function write(string $name, string $text, ?int $mtime = null): void
    {
        file_put_contents(self::LOGS.'/'.$name, $text);
        touch(self::LOGS.'/'.$name, $mtime ?? strtotime('2026-11-09 15:00:30 Europe/Vilnius'));
        if (!is_file(self::LOGS.'/main.log')) {
            file_put_contents(self::LOGS.'/main.log', "started\n");
        }
    }
}
