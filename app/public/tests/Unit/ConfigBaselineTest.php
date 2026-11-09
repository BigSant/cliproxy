<?php

namespace App\Tests\Unit;

use App\CliProxy\ClaudeCodeVersion;
use App\CliProxy\ConfigBaseline;
use App\Controller\SettingsController;
use PHPUnit\Framework\TestCase;

class ConfigBaselineTest extends TestCase
{
    public function testOnlyWhatDiffersIsPatched(): void
    {
        self::assertSame(
            ['observability' => ['logs' => ['error-logs-max-files' => 0]]],
            ConfigBaseline::missing(
                ['observability' => ['logs' => ['logs-max-total-size-mb' => 0, 'error-logs-max-files' => 10], 'usage' => ['usage-statistics-enabled' => true]]],
                ['observability' => ['logs' => ['logs-max-total-size-mb' => 0, 'error-logs-max-files' => 0], 'usage' => ['usage-statistics-enabled' => true]]],
            ),
        );
    }

    public function testThePolicyValuesMustBeWrittenOut(): void
    {
        $policy = ['access' => ['api-keys' => ['k']], 'observability' => [
            'usage' => ['usage-statistics-enabled' => true],
            'logs' => ['request-log' => true, 'logs-max-total-size-mb' => 0, 'error-logs-max-files' => 0],
        ]];
        self::assertSame([], ConfigBaseline::policyViolations($policy, true));

        // Left out, CLIProxyAPI's defaults would apply.
        self::assertCount(3, ConfigBaseline::policyViolations(['access' => ['api-keys' => ['k']]], true));
        self::assertCount(2, ConfigBaseline::policyViolations(['access' => ['api-keys' => ['k']]], false));
        $blankKeys = $policy;
        $blankKeys['access']['api-keys'] = ['', '  '];
        self::assertCount(1, ConfigBaseline::policyViolations($blankKeys, true));
        unset($blankKeys['access']);
        self::assertCount(1, ConfigBaseline::policyViolations($blankKeys, true));
    }

    public function testTheClaudeCodeVersionOnlyGoesUp(): void
    {
        self::assertSame('2.1.311', ClaudeCodeVersion::of('claude-cli/2.1.311 (external, cli, workload/cron)'));
        self::assertNull(ClaudeCodeVersion::of('claude-code-sdk/1.0'));
        self::assertNull(ClaudeCodeVersion::of('claude-cli/2.1'));

        self::assertSame('claude-cli/2.1.311 (external, cli)', ClaudeCodeVersion::raise(null, '2.1.311'), 'none set: CLIProxyAPI\'s own default');
        self::assertSame('claude-cli/2.1.311 (external, cli)', ClaudeCodeVersion::raise('claude-cli/2.1.280 (external, cli)', '2.1.311'));
        self::assertNull(ClaudeCodeVersion::raise('claude-cli/2.1.311 (external, cli)', '2.1.311'));
        self::assertNull(ClaudeCodeVersion::raise('claude-cli/2.1.400 (external, cli)', '2.1.311'), 'a higher value set by hand stays');
        self::assertNull(ClaudeCodeVersion::raise('claude-cli/2.1.280 (external, cli)', null));
    }

    public function testClientKeysAreHiddenInTheEditorAndComeBackOnSave(): void
    {
        $yaml = "access:\n  api-keys: [\"secret-one\", \"secret-two\"]\n";
        $masked = SettingsController::maskKeys($yaml, ['secret-one', 'secret-two']);

        self::assertStringNotContainsString('secret', $masked);
        self::assertSame($yaml, SettingsController::unmaskKeys($masked, ['secret-one', 'secret-two']));
    }
}
