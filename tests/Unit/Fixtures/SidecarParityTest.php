<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * Validates sidecar golden fixture JSON carries the keys native API must preserve.
 */
final class SidecarParityTest extends TestCase
{
	private string $fixtureDir;

	protected function setUp(): void
	{
		$this->fixtureDir = dirname(__DIR__, 2) . '/fixtures/sidecar';
	}

	public function testSummaryFixtureKeys(): void
	{
		$data = $this->loadJson('summary.json');
		foreach (['clients', 'connectedCount', 'totalClients', 'totalRx', 'totalTx', 'serverBootTime', 'cpu', 'mem', 'disk'] as $key) {
			self::assertArrayHasKey($key, $data, "summary missing {$key}");
		}
		self::assertNotEmpty($data['clients']);
		$client = $data['clients'][0];
		foreach (['id', 'name', 'ipv4Address', 'connected', 'endpoint', 'latestHandshakeAt', 'transferRx', 'transferTx', 'enabled', 'expiresAt'] as $key) {
			self::assertArrayHasKey($key, $client, "summary client missing {$key}");
		}
	}

	public function testBandwidthFixtureKeys(): void
	{
		$data = $this->loadJson('bandwidth.json');
		self::assertIsArray($data);
		if ($data === []) {
			return;
		}
		foreach (['ts', 'client_id', 'name', 'transfer_rx', 'transfer_tx'] as $key) {
			self::assertArrayHasKey($key, $data[0], "bandwidth row missing {$key}");
		}
	}

	public function testConnectionsFixtureIsArray(): void
	{
		$data = $this->loadJson('connections.json');
		self::assertIsArray($data);
		if ($data === []) {
			return;
		}
		foreach (['ts', 'client_id', 'name', 'event', 'endpoint'] as $key) {
			self::assertArrayHasKey($key, $data[0], "connection row missing {$key}");
		}
	}

	public function testGeoipFixtureKeys(): void
	{
		$data = $this->loadJson('geoip.json');
		self::assertIsArray($data);
		if ($data === []) {
			return;
		}
		foreach (['ip', 'country', 'country_code', 'city', 'region', 'lat', 'lon', 'isp', 'queried_at'] as $key) {
			self::assertArrayHasKey($key, $data[0], "geoip row missing {$key}");
		}
	}

	public function testSystemFixtureKeys(): void
	{
		$data = $this->loadJson('system.json');
		self::assertIsArray($data);
		self::assertNotEmpty($data);
		foreach (['ts', 'cpu_percent', 'mem_percent', 'disk_percent', 'net_rx_bytes', 'net_tx_bytes'] as $key) {
			self::assertArrayHasKey($key, $data[0], "system row missing {$key}");
		}
	}

	public function testStatusFixtureKeys(): void
	{
		$data = $this->loadJson('status.json');
		foreach (['status', 'version', 'wg_easy', 'poller'] as $key) {
			self::assertArrayHasKey($key, $data, "status missing {$key}");
		}
	}

	public function testConfigFixtureKeys(): void
	{
		$data = $this->loadJson('config.json');
		self::assertArrayHasKey('configuration', $data);
		self::assertIsString($data['configuration']);
		self::assertStringContainsString('[Interface]', $data['configuration']);
	}

	/** @return array<string, mixed>|list<mixed> */
	private function loadJson(string $filename): array
	{
		$path = $this->fixtureDir . '/' . $filename;
		self::assertFileExists($path);
		$data = json_decode((string) file_get_contents($path), true);
		self::assertIsArray($data);
		return $data;
	}
}
