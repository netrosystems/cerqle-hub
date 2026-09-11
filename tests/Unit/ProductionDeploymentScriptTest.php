<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDeploymentScriptTest extends TestCase
{
    public function test_deployment_explicitly_reloads_and_requires_campaign_workers(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/deploy-production.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('stop \'cerqle-worker:*\'', $script);
        $this->assertStringContainsString('start \'cerqle-worker:*\'', $script);
        $this->assertStringContainsString('start \'cerqle-worker:*\' || true', $script);
        $this->assertStringContainsString('stop "$BROADCAST_PROGRAM:*"', $script);
        $this->assertStringContainsString('start "$BROADCAST_PROGRAM:*"', $script);
        $this->assertStringContainsString('start "$BROADCAST_PROGRAM:*" || true', $script);
        $this->assertStringContainsString('RUNNING_BROADCAST_WORKERS', $script);
        $this->assertStringContainsString('LEGACY_BROADCAST_BACKUP', $script);
        $this->assertStringContainsString('ERROR: Dedicated broadcast workers are not installed', $script);
        $this->assertStringNotContainsString('WARNING: Dedicated broadcast workers are not installed', $script);
    }
}
