<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Controller;

use OCA\NcWireguard\Controller\SettingsController;
use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Db\PollStateMapper;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyClient;
use OCA\NcWireguard\Service\WgEasyProbe;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SettingsControllerEndpointTest extends TestCase
{
	public function testIsValidEndpointHostRejectsSchemePortSpaces(): void
	{
		self::assertFalse(SettingsController::isValidEndpointHost('https://x'));
		self::assertFalse(SettingsController::isValidEndpointHost('a b'));
		self::assertFalse(SettingsController::isValidEndpointHost('host:51820'));
		self::assertFalse(SettingsController::isValidEndpointHost(''));
		self::assertFalse(SettingsController::isValidEndpointHost('vpn.example/path'));
		self::assertFalse(SettingsController::isValidEndpointHost('999.1.1.1'));
		self::assertFalse(SettingsController::isValidEndpointHost('256.0.0.1'));
		self::assertFalse(SettingsController::isValidEndpointHost('1.2.3'));
		self::assertTrue(SettingsController::isValidEndpointHost('cloud-vdroners.ddns.net'));
		self::assertTrue(SettingsController::isValidEndpointHost('10.0.0.84'));
	}

	public function testUpdateEndpointRejectsInvalidAndDoesNotCallEngine(): void
	{
		$client = $this->createMock(WgEasyClient::class);
		$client->expects(self::never())->method('updateEndpointHost');
		$client->expects(self::never())->method('getUserConfig');

		$controller = new class (
			$this->createMock(IRequest::class),
			$this->createMock(IConfig::class),
			$this->createMock(AppSettings::class),
			$this->createMock(NativeHealthService::class),
			$this->createMock(WgEasyProbe::class),
			$this->createMock(MetricsHeartbeatMapper::class),
			$this->createMock(PollStateMapper::class),
			$client,
			$this->createMock(LoggerInterface::class),
			['host' => 'host:51820'],
		) extends SettingsController {
			public function __construct(
				IRequest $request,
				IConfig $config,
				AppSettings $appSettings,
				NativeHealthService $nativeHealth,
				WgEasyProbe $wgEasyProbe,
				MetricsHeartbeatMapper $heartbeatMapper,
				PollStateMapper $pollStateMapper,
				WgEasyClient $wgEasyClient,
				LoggerInterface $logger,
				private array $body,
			) {
				parent::__construct(
					$request,
					$config,
					$appSettings,
					$nativeHealth,
					$wgEasyProbe,
					$heartbeatMapper,
					$pollStateMapper,
					$wgEasyClient,
					$logger,
				);
			}

			protected function readJsonBody(): mixed
			{
				return $this->body;
			}
		};

		$resp = $controller->updateEndpoint();
		self::assertSame(400, $resp->getStatus());
		$data = $resp->getData();
		self::assertArrayHasKey('error', $data);
		self::assertStringNotContainsString('secret', json_encode($data, JSON_THROW_ON_ERROR));
	}
}
