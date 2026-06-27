<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\SchemaRegistry;
use PHPUnit\Framework\TestCase;

class SchemaRegistryTest extends TestCase
{
	public function testDefinesSixTables(): void
	{
		$this->assertCount(6, SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_bandwidth_log', SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_metrics_heartbeat', SchemaRegistry::TABLES);
	}

	public function testBandwidthColumns(): void
	{
		$this->assertContains('transfer_rx', SchemaRegistry::TABLES['nc_wg_bandwidth_log']);
		$this->assertContains('transfer_tx', SchemaRegistry::TABLES['nc_wg_bandwidth_log']);
	}
}
