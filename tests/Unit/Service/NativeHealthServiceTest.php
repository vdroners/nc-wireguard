<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTime;
use OCA\NcWireguard\Db\MetricsHeartbeat;
use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\EngineResolver;
use OCA\NcWireguard\Service\HostProcCollector;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyClient;
use PHPUnit\Framework\TestCase;

final class NativeHealthServiceTest extends TestCase
{
	public function testHealthyWhenHeartbeatFreshAndWgEasyOk(): void
	{
		$heartbeat = new MetricsHeartbeat();
		$heartbeat->setLastPollAt(new DateTime('-10 seconds'));
		$heartbeat->setSuccess(true);
		$heartbeat->setWgEasyOk(true);

		$mapper = $this->createMock(MetricsHeartbeatMapper::class);
		$mapper->method('findSingleton')->willReturn($heartbeat);

		$settings = $this->createMock(AppSettings::class);
		$settings->method('getPollIntervalSeconds')->willReturn(30);

		$hostProc = new HostProcCollector(dirname(__DIR__, 2) . '/fixtures/proc');
		$wgEasy = $this->createMock(WgEasyClient::class);
		$engines = $this->createMock(EngineResolver::class);
		$engines->method('useNative')->willReturn(false);

		$service = new NativeHealthService($mapper, $wgEasy, $settings, $hostProc, $engines);
		$health = $service->getHealth('1.3.0-rc');

		self::assertSame('ok', $health['status']);
		self::assertTrue($health['wg_easy']);
		self::assertTrue($health['poller']);
		self::assertTrue($health['host_metrics']);
	}

	public function testProbesWgEasyWhenHeartbeatMissing(): void
	{
		$mapper = $this->createMock(MetricsHeartbeatMapper::class);
		$mapper->method('findSingleton')->willReturn(null);

		$settings = $this->createMock(AppSettings::class);
		$settings->method('getPollIntervalSeconds')->willReturn(30);

		$wgEasy = $this->createMock(WgEasyClient::class);
		$wgEasy->method('apiHostResolves')->willReturn(true);
		$wgEasy->expects($this->once())->method('getClients')->willReturn([['id' => 1, 'name' => 'test']]);
		$engines = $this->createMock(EngineResolver::class);
		$engines->method('useNative')->willReturn(false);

		$hostProc = new HostProcCollector('/nonexistent/host/proc');
		$service = new NativeHealthService($mapper, $wgEasy, $settings, $hostProc, $engines);
		$health = $service->getHealth('1.3.0-rc');

		self::assertTrue($health['wg_easy']);
		self::assertFalse($health['poller']);
		self::assertSame('degraded', $health['status']);
	}

	public function testSkipsProbeWhenHostDoesNotResolve(): void
	{
		$mapper = $this->createMock(MetricsHeartbeatMapper::class);
		$mapper->method('findSingleton')->willReturn(null);

		$settings = $this->createMock(AppSettings::class);
		$settings->method('getPollIntervalSeconds')->willReturn(30);

		$wgEasy = $this->createMock(WgEasyClient::class);
		$wgEasy->method('apiHostResolves')->willReturn(false);
		$wgEasy->expects($this->never())->method('getClients');
		$engines = $this->createMock(EngineResolver::class);
		$engines->method('useNative')->willReturn(false);

		$service = new NativeHealthService(
			$mapper,
			$wgEasy,
			$settings,
			new HostProcCollector('/nonexistent/host/proc'),
			$engines,
		);
		$health = $service->getHealth('2.3.5');

		self::assertFalse($health['wg_easy']);
		self::assertSame('degraded', $health['status']);
	}

	public function testSkipsProbeWhenNativeEngineIsActive(): void
	{
		$mapper = $this->createMock(MetricsHeartbeatMapper::class);
		$mapper->method('findSingleton')->willReturn(null);

		$settings = $this->createMock(AppSettings::class);
		$settings->method('getPollIntervalSeconds')->willReturn(30);

		$wgEasy = $this->createMock(WgEasyClient::class);
		$wgEasy->expects($this->never())->method('getClients');
		$engines = $this->createMock(EngineResolver::class);
		$engines->method('useNative')->willReturn(true);

		$service = new NativeHealthService(
			$mapper,
			$wgEasy,
			$settings,
			new HostProcCollector('/nonexistent/host/proc'),
			$engines,
		);
		$health = $service->getHealth('2.3.5');

		self::assertTrue($health['wg_easy']);
	}
}
