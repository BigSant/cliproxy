<?php

namespace App\Tests\Unit;

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

    public function testClientKeysAreHiddenInTheEditorAndComeBackOnSave(): void
    {
        $yaml = "access:\n  api-keys: [\"secret-one\", \"secret-two\"]\n";
        $masked = SettingsController::maskKeys($yaml, ['secret-one', 'secret-two']);

        self::assertStringNotContainsString('secret', $masked);
        self::assertSame($yaml, SettingsController::unmaskKeys($masked, ['secret-one', 'secret-two']));
    }
}
