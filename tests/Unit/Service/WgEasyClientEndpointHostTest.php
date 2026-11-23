<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Tests\Unit\Doubles\RecordingWgEasyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WgEasyClientEndpointHostTest extends TestCase
{
	private function client(?AppSettings $settings = null): RecordingWgEasyClient
	{
		return new RecordingWgEasyClient(
			$settings ?? $this->createMock(AppSettings::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** Full wg-easy v15 userconfig shape (all fields the schema requires). */
	private function sampleUserConfig(string $host = 'old.example.net'): array
	{
		return [
			'host' => $host,
			'port' => 51820,
			'defaultMtu' => 1420,
			'defaultPersistentKeepalive' => 25,
			'defaultDns' => ['1.1.1.1'],
			'defaultAllowedIps' => ['10.0.0.0/24', '10.8.0.0/24'],
			'defaultJC' => 4,
			'defaultJMin' => 10,
			'defaultJMax' => 50,
			'defaultI1' => '',
			'defaultI2' => '',
			'defaultI3' => '',
			'defaultI4' => '',
			'defaultI5' => '',
		];
	}

	public function testUpdateEndpointHostPostsFullUserconfigWithOnlyHostChanged(): void
	{
		$client = $this->client();
		$original = $this->sampleUserConfig('vpn.old.example');
		$client->responses = [
			['http_code' => 200, 'error' => '', 'json' => $original],
			['http_code' => 200, 'error' => '', 'json' => array_merge($original, ['host' => 'cloud-vdroners.ddns.net'])],
		];

		$result = $client->updateEndpointHost('cloud-vdroners.ddns.net');

		self::assertTrue($result['ok']);
		self::assertCount(2, $client->calls);
		self::assertSame(['GET', '/api/admin/userconfig', null], $client->calls[0]);
		self::assertSame('POST', $client->calls[1][0]);
		self::assertSame('/api/admin/userconfig', $client->calls[1][1]);
		$posted = $client->calls[1][2];
		self::assertIsArray($posted);
		self::assertSame('cloud-vdroners.ddns.net', $posted['host']);

		$expectedRest = $original;
		unset($expectedRest['host']);
		$postedRest = $posted;
		unset($postedRest['host']);
		self::assertSame($expectedRest, $postedRest);
	}

	public function testUpdateEndpointHostEngineErrorReturnsOkFalseWithoutSecrets(): void
	{
		$client = $this->client();
		$original = $this->sampleUserConfig();
		$client->responses = [
			['http_code' => 200, 'error' => '', 'json' => $original],
			['http_code' => 500, 'error' => 'engine boom', 'json' => ['secret' => 'should-not-leak']],
		];

		$result = $client->updateEndpointHost('new.example.net');

		self::assertFalse($result['ok']);
		self::assertArrayNotHasKey('body', $result);
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('should-not-leak', $encoded);
		self::assertStringNotContainsString('secret', $encoded);
	}

	public function testGetServerDefaultsUsesUserconfigEndpointHost(): void
	{
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getWgEasyAdminUrl')->willReturn('http://127.0.0.1:51821');
		$client = $this->client($settings);
		$user = $this->sampleUserConfig('cloud-vdroners.ddns.net');
		$client->responses = [
			['http_code' => 200, 'error' => '', 'json' => ['sessionTimeout' => 3600]],
			['http_code' => 200, 'error' => '', 'json' => [
				'port' => 51820,
				'mtu' => 1420,
				'ipv4Cidr' => '10.8.0.0/24',
				'ipv6Cidr' => '',
				'name' => 'wg0',
				'device' => 'eth0',
				'enabled' => true,
			]],
			['http_code' => 200, 'error' => '', 'json' => $user],
		];

		$result = $client->getServerDefaults();

		self::assertTrue($result['ok']);
		self::assertSame('cloud-vdroners.ddns.net', $result['host']);
		self::assertSame(51820, $result['port']);
		self::assertSame('cloud-vdroners.ddns.net:51820', $result['endpoint']);
		self::assertSame('127.0.0.1', $result['adminUiHost']);
		self::assertArrayNotHasKey('endpointError', $result);
	}

	public function testGetServerDefaultsUserconfigFailureSetsEndpointErrorWithoutSecrets(): void
	{
		$settings = $this->createMock(AppSettings::class);
		$settings->method('getWgEasyAdminUrl')->willReturn('http://127.0.0.1:51821');
		$client = $this->client($settings);
		$client->responses = [
			['http_code' => 200, 'error' => '', 'json' => ['sessionTimeout' => 3600]],
			['http_code' => 200, 'error' => '', 'json' => [
				'port' => 51820,
				'mtu' => 1420,
				'ipv4Cidr' => '10.8.0.0/24',
				'ipv6Cidr' => '',
				'name' => 'wg0',
				'device' => 'eth0',
				'enabled' => true,
			]],
			['http_code' => 500, 'error' => 'engine secret-body-xyz', 'json' => ['secret' => 'should-not-leak']],
		];

		$result = $client->getServerDefaults();

		self::assertTrue($result['ok']);
		self::assertNull($result['host']);
		self::assertArrayHasKey('endpointError', $result);
		self::assertNotSame('', (string) $result['endpointError']);
		$encoded = json_encode($result, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('should-not-leak', $encoded);
		self::assertStringNotContainsString('secret-body-xyz', $encoded);
		self::assertStringNotContainsString('secret', $encoded);
	}
}
