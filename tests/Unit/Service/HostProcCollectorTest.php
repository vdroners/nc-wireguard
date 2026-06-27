<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\HostProcCollector;
use PHPUnit\Framework\TestCase;

final class HostProcCollectorTest extends TestCase
{
	private string $fixtureRoot;

	protected function setUp(): void
	{
		$this->fixtureRoot = dirname(__DIR__, 2) . '/fixtures/proc';
	}

	public function testCollectFromFixtures(): void
	{
		$collector = new HostProcCollector($this->fixtureRoot);
		self::assertSame($this->fixtureRoot, $collector->resolveProcRoot());

		$first = $collector->collect(null);
		self::assertSame(0.0, $first['cpu_percent']);
		self::assertGreaterThan(0, $first['mem_percent']);
		self::assertSame(9876543210 + 1000, $first['net_rx_bytes']);
		self::assertSame(8765432109 + 2000, $first['net_tx_bytes']);
		self::assertArrayHasKey('cpu_snapshot', $first);

		$second = $collector->collect($first['cpu_snapshot']);
		self::assertSame(0.0, $second['cpu_percent']); // same fixture → zero delta
		self::assertGreaterThan(0, $first['cpu_snapshot']['total']);
	}

	public function testFallsBackToProcWhenMountMissing(): void
	{
		$collector = new HostProcCollector('/nonexistent/host/proc');
		self::assertSame('/proc', $collector->resolveProcRoot());
	}

	public function testIsAvailableWithFixtureMount(): void
	{
		$collector = new HostProcCollector($this->fixtureRoot);
		self::assertTrue($collector->isAvailable());
	}

	public function testReadBootTimeFromFixtureStat(): void
	{
		$collector = new HostProcCollector($this->fixtureRoot);
		$iso = $collector->readBootTimeIso();
		self::assertNotNull($iso);
		self::assertStringContainsString('2023', $iso);
	}
}
