<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Controller;

use DateTime;
use OCA\NcWireguard\Controller\SettingsController;
use OCA\NcWireguard\Db\MetricsHeartbeat;
use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Db\PollStateMapper;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyProbe;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class SettingsControllerHealthTest extends TestCase
{
	private function makeController(
		?MetricsHeartbeat $heartbeat,
		array $handshakeStats,
		array $legacyKeys,
	): SettingsController {
		$heartbeatMapper = $this->createMock(MetricsHeartbeatMapper::class);
		$heartbeatMapper->method('findSingleton')->willReturn($heartbeat);

		$pollStateMapper = $this->createMock(PollStateMapper::class);
		$pollStateMapper->method('countHandshakeStats')->willReturn($handshakeStats);

		$appSettings = $this->createMock(AppSettings::class);
		$appSettings->method('listPresentLegacyKeys')->willReturn($legacyKeys);

		return new SettingsController(
			$this->createMock(IRequest::class),
			$this->createMock(IConfig::class),
			$appSettings,
			$this->createMock(NativeHealthService::class),
			$this->createMock(WgEasyProbe::class),
			$heartbeatMapper,
			$pollStateMapper,
		);
	}

	public function testUnknownWhenNoHeartbeat(): void
	{
		$controller = $this->makeController(null, ['total' => 0, 'with_handshake' => 0, 'never' => 0], ['dashboard_internal_url']);
		$resp = $controller->getHealth();
		$data = $resp->getData();

		self::assertSame(200, $resp->getStatus());
		self::assertSame('unknown', $data['status']);
		self::assertFalse($data['known']);
		self::assertNull($data['wg_easy_ok']);
		self::assertNull($data['peers_total']);
		self::assertSame(['dashboard_internal_url'], $data['legacy_keys_present']);
		self::assertArrayNotHasKey('wg_easy_password', $data);
		self::assertArrayNotHasKey('wg_sync_token', $data);
		self::assertArrayNotHasKey('geoip_api_key', $data);
	}

	public function testReturnsCountsAndNoSecretsWhenHeartbeatPresent(): void
	{
		$heartbeat = new MetricsHeartbeat();
		$heartbeat->setLastPollAt(new DateTime('2026-11-23T20:00:00+00:00'));
		$heartbeat->setSuccess(true);
		$heartbeat->setWgEasyOk(true);
		$heartbeat->setErrorMessage(null);

		$controller = $this->makeController(
			$heartbeat,
			['total' => 4, 'with_handshake' => 2, 'never' => 2],
			['dashboard_internal_url', 'wg_sync_url'],
		);
		$resp = $controller->getHealth();
		$data = $resp->getData();

		self::assertSame(200, $resp->getStatus());
		self::assertSame('ok', $data['status']);
		self::assertTrue($data['known']);
		self::assertTrue($data['wg_easy_ok']);
		self::assertSame(4, $data['peers_total']);
		self::assertSame(2, $data['peers_with_handshake']);
		self::assertSame(2, $data['peers_never_handshake']);
		self::assertSame(['dashboard_internal_url', 'wg_sync_url'], $data['legacy_keys_present']);

		$encoded = json_encode($data, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('enc:v1:', $encoded);
		self::assertStringNotContainsString('password', $encoded);
		self::assertStringNotContainsString('token-value', $encoded);
		self::assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+\.\d+/', $encoded);
	}
}
