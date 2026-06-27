<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\WgEasyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mock wg-easy configuration normalization for CI (no live HTTP).
 */
final class WgEasyClientConfigurationTest extends TestCase
{
	private WgEasyClient $client;

	protected function setUp(): void
	{
		$settings = $this->createMock(AppSettings::class);
		$this->client = new WgEasyClient($settings, $this->createMock(LoggerInterface::class));
	}

	public function testWrapsPlainTextConfiguration(): void
	{
		$fixture = json_decode(
			(string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/sidecar/config.json'),
			true
		);
		self::assertIsArray($fixture);
		$text = $fixture['configuration'];

		$result = $this->client->formatConfigurationBody($text, false);
		self::assertArrayHasKey('configuration', $result);
		self::assertSame($text, $result['configuration']);
	}

	public function testPassesThroughJsonBody(): void
	{
		$payload = ['configuration' => '[Interface]\nPrivateKey = test\n', 'clientId' => 2];
		$json = json_encode($payload) ?: '';
		$result = $this->client->formatConfigurationBody($json, true);
		self::assertSame($payload, $result);
	}

	public function testFallsBackWhenJsonInvalid(): void
	{
		$result = $this->client->formatConfigurationBody('not-json', true);
		self::assertSame(['configuration' => 'not-json'], $result);
	}
}
