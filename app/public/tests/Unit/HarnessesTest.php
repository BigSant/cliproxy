<?php

namespace App\Tests\Unit;

use App\CliProxy\Harnesses;
use PHPUnit\Framework\TestCase;

class HarnessesTest extends TestCase
{
    public function testEveryHarnessBehindItsNewestReleaseIsBroughtUpToIt(): void
    {
        self::assertSame(
            [
                'upstream' => ['claude' => ['header-defaults' => ['user-agent' => 'claude-cli/2.1.321 (external, cli)']]],
                'oauth' => ['providers' => ['codex' => ['header-defaults' => ['user-agent' => 'codex-tui/0.167.0 (Mac OS 26.5.2; arm64) iTerm.app/3.6.11 (codex-tui; 0.167.0)']]]],
            ],
            Harnesses::patch([], ['claude' => '2.1.321', 'codex' => '0.167.0']),
            'none configured: CLIProxyAPI\'s own',
        );
        self::assertSame(
            ['upstream' => ['claude' => ['header-defaults' => ['user-agent' => 'claude-cli/2.1.321 (external, cli)']]]],
            Harnesses::patch(['upstream' => ['claude' => ['header-defaults' => ['user-agent' => 'claude-cli/2.1.311 (external, cli)']]]], ['claude' => '2.1.321']),
        );
    }

    public function testNeverLowered(): void
    {
        $config = ['upstream' => ['claude' => ['header-defaults' => ['user-agent' => 'claude-cli/2.1.400 (external, cli)']]],
            'oauth' => ['providers' => ['codex' => ['header-defaults' => ['user-agent' => 'codex_cli_rs/0.167.0 (Linux; x86_64)']]]]];

        self::assertSame([], Harnesses::patch($config, ['claude' => '2.1.321', 'codex' => '0.167.0']));
        self::assertSame('2.1.311', Harnesses::version('claude', 'claude-cli/2.1.311 (external, cli, workload/cron)'));
        self::assertNull(Harnesses::version('claude', 'claude-cli/2.1'));
    }
}
