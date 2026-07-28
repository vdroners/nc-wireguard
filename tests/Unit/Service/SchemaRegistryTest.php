<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\SchemaRegistry;
use PHPUnit\Framework\TestCase;

class SchemaRegistryTest extends TestCase
{
	public function testDefinesTheMetricsAndPeerStoreTables(): void
	{
		$this->assertCount(9, SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_bandwidth_log', SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_metrics_heartbeat', SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_peers', SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_peer_secrets', SchemaRegistry::TABLES);
		$this->assertArrayHasKey('nc_wg_server', SchemaRegistry::TABLES);
	}

	public function testBandwidthColumns(): void
	{
		$this->assertContains('transfer_rx', SchemaRegistry::TABLES['nc_wg_bandwidth_log']);
		$this->assertContains('transfer_tx', SchemaRegistry::TABLES['nc_wg_bandwidth_log']);
	}

	public function testPeerIdentityColumns(): void
	{
		$peers = SchemaRegistry::TABLES['nc_wg_peers'];
		$this->assertContains('uuid', $peers);
		$this->assertContains('public_key', $peers);
		$this->assertContains('wg_easy_id', $peers);
		$this->assertContains('has_amnezia', $peers);
	}

	public function testMetricsTablesCarryRemapColumns(): void
	{
		$this->assertContains('peer_uuid', SchemaRegistry::TABLES['nc_wg_bandwidth_log']);
		$this->assertContains('peer_uuid', SchemaRegistry::TABLES['nc_wg_connection_log']);
		$this->assertContains('peer_uuid', SchemaRegistry::TABLES['nc_wg_poll_state']);
		$this->assertContains('public_key', SchemaRegistry::TABLES['nc_wg_poll_state']);
	}
}
