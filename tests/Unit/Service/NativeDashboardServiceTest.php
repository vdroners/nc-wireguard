<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTime;
use OCA\NcWireguard\Db\BandwidthLog;
use OCA\NcWireguard\Db\BandwidthLogMapper;
use OCA\NcWireguard\Db\ConnectionLog;
use OCA\NcWireguard\Db\ConnectionLogMapper;
use OCA\NcWireguard\Db\GeoIpCache;
use OCA\NcWireguard\Db\GeoIpCacheMapper;
use OCA\NcWireguard\Db\SystemMetrics;
use OCA\NcWireguard\Db\SystemMetricsMapper;
use OCA\NcWireguard\Service\ConnectionStateMachine;
use OCA\NcWireguard\Service\HostProcCollector;
use OCA\NcWireguard\Service\NativeDashboardService;
use OCA\NcWireguard\Service\SystemMetricsCollector;
use OCA\NcWireguard\Service\WgEasyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NativeDashboardServiceTest extends TestCase
{
	private string $fixtureDir;

	protected function setUp(): void
	{
		$this->fixtureDir = dirname(__DIR__, 2) . '/fixtures/sidecar';
	}

	public function testBuildSummaryMatchesFixtureStructure(): void
	{
		$fixture = json_decode((string) file_get_contents($this->fixtureDir . '/summary.json'), true);
		self::assertIsArray($fixture);

		$wgClients = array_map(static function (array $c): array {
			return [
				'id' => $c['id'],
				'name' => $c['name'],
				'ipv4Address' => $c['ipv4Address'],
				'endpoint' => $c['endpoint'],
				'latestHandshakeAt' => $c['latestHandshakeAt'],
				'transferRx' => $c['transferRx'],
				'transferTx' => $c['transferTx'],
				'enabled' => $c['enabled'],
				'expiresAt' => $c['expiresAt'],
			];
		}, $fixture['clients']);

		$wgEasy = $this->createMock(WgEasyClient::class);
		$wgEasy->method('getClients')->willReturn($wgClients);

		$hostProc = $this->createMock(HostProcCollector::class);
		$hostProc->method('readBootTimeIso')->willReturn($fixture['serverBootTime']);

		$systemCollector = $this->createMock(SystemMetricsCollector::class);
		$systemCollector->method('collect')->willReturn([
			'cpu_percent' => $fixture['cpu'],
			'mem_percent' => $fixture['mem'],
			'disk_percent' => $fixture['disk'],
		]);

		$service = new NativeDashboardService(
			$wgEasy,
			new ConnectionStateMachine(),
			$this->createMock(BandwidthLogMapper::class),
			$this->createMock(ConnectionLogMapper::class),
			$this->createMock(GeoIpCacheMapper::class),
			$this->createMock(SystemMetricsMapper::class),
			$systemCollector,
			$hostProc,
		);

		$response = $service->buildSummary();
		self::assertArrayNotHasKey('error', $response);
		foreach (array_keys($fixture) as $key) {
			self::assertArrayHasKey($key, $response, "summary response missing {$key}");
		}
		self::assertCount(count($fixture['clients']), $response['clients']);
		$clientKeys = array_keys($fixture['clients'][0]);
		foreach ($response['clients'] as $client) {
			foreach ($clientKeys as $key) {
				self::assertArrayHasKey($key, $client);
			}
		}
		self::assertSame($fixture['connectedCount'], $response['connectedCount']);
		self::assertSame($fixture['totalClients'], $response['totalClients']);
	}

	public function testFetchBandwidthRowShape(): void
	{
		$row = new BandwidthLog();
		$row->setTs(new DateTime('2026-06-27T15:57:33+00:00'));
		$row->setClientId(5);
		$row->setName('Takaradi GCS');
		$row->setTransferRx(0);
		$row->setTransferTx(0);

		$mapper = $this->createMock(BandwidthLogMapper::class);
		$mapper->method('findSinceHours')->with(24, null)->willReturn([$row]);

		$service = $this->makeService($mapper);
		$rows = $service->fetchBandwidth(24, null);
		self::assertCount(1, $rows);
		foreach (['ts', 'client_id', 'name', 'transfer_rx', 'transfer_tx'] as $key) {
			self::assertArrayHasKey($key, $rows[0]);
		}
	}

	public function testFetchSystemRowShape(): void
	{
		$row = new SystemMetrics();
		$row->setTs(new DateTime('2026-06-27T15:57:33+00:00'));
		$row->setCpuPercent(12.5);
		$row->setMemPercent(20.0);
		$row->setDiskPercent(38.3);
		$row->setNetRxBytes(100);
		$row->setNetTxBytes(200);

		$mapper = $this->createMock(SystemMetricsMapper::class);
		$mapper->method('findSinceHours')->with(24)->willReturn([$row]);

		$service = $this->makeService(systemMetricsMapper: $mapper);
		$rows = $service->fetchSystem(24);
		self::assertCount(1, $rows);
		foreach (['ts', 'cpu_percent', 'mem_percent', 'disk_percent', 'net_rx_bytes', 'net_tx_bytes'] as $key) {
			self::assertArrayHasKey($key, $rows[0]);
		}
	}

	public function testFetchConnectionsEnrichesGeo(): void
	{
		$row = new ConnectionLog();
		$row->setTs(new DateTime('2026-06-27T15:57:33+00:00'));
		$row->setClientId(2);
		$row->setName('GCS');
		$row->setEvent('connected');
		$row->setEndpoint('203.0.113.5:51820');

		$connMapper = $this->createMock(ConnectionLogMapper::class);
		$connMapper->method('findSinceDays')->willReturn([$row]);

		$geoMapper = $this->createMock(GeoIpCacheMapper::class);
		$geoMapper->method('findGeoSummaryByIp')->with('203.0.113.5')->willReturn([
			'country' => 'United States',
			'country_code' => 'US',
			'city' => 'Example',
			'lat' => 37.0,
			'lon' => -122.0,
			'isp' => 'Example ISP',
		]);

		$service = $this->makeService(connectionMapper: $connMapper, geoIpMapper: $geoMapper);
		$rows = $service->fetchConnections(7, null);
		self::assertArrayHasKey('geo', $rows[0]);
		self::assertSame('US', $rows[0]['geo']['country_code']);
	}

	public function testBuildSummaryReturnsWgEasyError(): void
	{
		$wgEasy = $this->createMock(WgEasyClient::class);
		$wgEasy->method('getClients')->willReturn(null);
		$service = $this->makeService(wgEasy: $wgEasy);
		$response = $service->buildSummary();
		self::assertSame(['error' => 'Cannot reach wg-easy'], $response);
	}

	private function makeService(
		?BandwidthLogMapper $bandwidthMapper = null,
		?ConnectionLogMapper $connectionMapper = null,
		?GeoIpCacheMapper $geoIpMapper = null,
		?SystemMetricsMapper $systemMetricsMapper = null,
		?WgEasyClient $wgEasy = null,
	): NativeDashboardService {
		$hostProc = $this->createMock(HostProcCollector::class);
		$hostProc->method('readBootTimeIso')->willReturn('2026-06-20T23:10:13+00:00');

		$systemCollector = $this->createMock(SystemMetricsCollector::class);
		$systemCollector->method('collect')->willReturn([
			'cpu_percent' => 0.0,
			'mem_percent' => 0.0,
			'disk_percent' => 0.0,
		]);

		return new NativeDashboardService(
			$wgEasy ?? $this->createMock(WgEasyClient::class),
			new ConnectionStateMachine(),
			$bandwidthMapper ?? $this->createMock(BandwidthLogMapper::class),
			$connectionMapper ?? $this->createMock(ConnectionLogMapper::class),
			$geoIpMapper ?? $this->createMock(GeoIpCacheMapper::class),
			$systemMetricsMapper ?? $this->createMock(SystemMetricsMapper::class),
			$systemCollector,
			$hostProc,
		);
	}
}
