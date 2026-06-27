<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\SidecarImportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SidecarImportKeysTest extends TestCase
{
	public function testNormalizeTsKeyStripsMicroseconds(): void
	{
		$method = new ReflectionMethod(SidecarImportService::class, 'normalizeTsKey');
		$method->setAccessible(true);
		$service = new SidecarImportService(
			new \OCA\NcWireguard\Tests\Stubs\NullDbConnection(),
			$this->createMock(\OCA\NcWireguard\Db\BandwidthLogMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\ConnectionLogMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\GeoIpCacheMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\SystemMetricsMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\PollStateMapper::class),
		);

		$normalized = $method->invoke(
			$service,
			'2026-06-18T14:22:40.538464+00:00'
		);
		self::assertSame('2026-06-18 14:22:40', $normalized);

		$fromMysql = $method->invoke($service, '2026-06-18 14:22:40');
		self::assertSame('2026-06-18 14:22:40', $fromMysql);
	}

	public function testDefaultSqlitePath(): void
	{
		self::assertStringContainsString('dashboard.db', SidecarImportService::DEFAULT_SQLITE_PATH);
	}

	public function testPollStateDerivationFromConnectionLog(): void
	{
		$sqlitePath = sys_get_temp_dir() . '/nc_wg_poll_derive_' . uniqid('', true) . '.db';
		$sqlite = new \PDO('sqlite:' . $sqlitePath);
		$sqlite->exec(
			'CREATE TABLE connection_log (client_id INTEGER, event TEXT, endpoint TEXT, ts TEXT)'
		);
		$sqlite->exec(
			"INSERT INTO connection_log VALUES (5, 'connected', '9.9.9.9:51820', '2026-06-01T10:00:00+00:00')"
		);
		$sqlite->exec(
			"INSERT INTO connection_log VALUES (5, 'disconnected', NULL, '2026-06-01T11:00:00+00:00')"
		);

		$method = new ReflectionMethod(SidecarImportService::class, 'loadPollStateSource');
		$method->setAccessible(true);
		$service = new SidecarImportService(
			new \OCA\NcWireguard\Tests\Stubs\NullDbConnection(),
			$this->createMock(\OCA\NcWireguard\Db\BandwidthLogMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\ConnectionLogMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\GeoIpCacheMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\SystemMetricsMapper::class),
			$this->createMock(\OCA\NcWireguard\Db\PollStateMapper::class),
		);

		$rows = $method->invoke($service, $sqlite);
		self::assertCount(1, $rows);
		self::assertSame(5, $rows[0]['client_id']);
		self::assertFalse($rows[0]['connected']);
		self::assertNull($rows[0]['endpoint']);

		unlink($sqlitePath);
	}
}
