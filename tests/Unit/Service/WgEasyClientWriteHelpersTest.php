<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\WgEasyClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit coverage for v2.1 write helpers (no live HTTP).
 */
final class WgEasyClientWriteHelpersTest extends TestCase
{
	private WgEasyClient $client;

	protected function setUp(): void
	{
		$settings = $this->createMock(AppSettings::class);
		$this->client = new WgEasyClient($settings, $this->createMock(LoggerInterface::class));
	}

	public function testMergeUpdatePayloadNormalizesCsvListsAndStripsEmptyDns(): void
	{
		$ref = new ReflectionClass($this->client);
		$method = $ref->getMethod('mergeUpdatePayload');
		$method->setAccessible(true);

		$existing = [
			'name' => 'old',
			'enabled' => true,
			'expiresAt' => null,
			'ipv4Address' => '10.8.0.10',
			'ipv6Address' => null,
			'preUp' => '',
			'postUp' => '',
			'preDown' => '',
			'postDown' => '',
			'allowedIps' => ['0.0.0.0/0', '::/0'],
			'serverAllowedIps' => [],
			'firewallIps' => null,
			'dns' => ['8.8.8.8'],
			'mtu' => 1420,
			'persistentKeepalive' => 0,
			'serverEndpoint' => 'vpn.example:51820',
		];

		$payload = $method->invoke($this->client, $existing, [
			'name' => 'field-peer',
			'allowedIps' => '10.0.0.0/24,10.8.0.0/24',
			'dns' => '',
			'mtu' => 1420,
			'persistentKeepalive' => 25,
		]);

		self::assertSame('field-peer', $payload['name']);
		self::assertSame(['10.0.0.0/24', '10.8.0.0/24'], $payload['allowedIps']);
		self::assertSame(25, $payload['persistentKeepalive']);
		self::assertSame([], $payload['dns']);
	}

	public function testTotpRequiredConstant(): void
	{
		self::assertSame('TOTP_REQUIRED', WgEasyClient::ERR_TOTP_REQUIRED);
	}

	/**
	 * wg-easy v15 nests the token under the client's oneTimeLink relation.
	 * Shape confirmed against a live container (see docs/API_PARITY.md).
	 */
	public function testExtractOneTimeLinkReadsNestedV15Shape(): void
	{
		self::assertSame(
			['aaec8ec', '2026-07-27T14:56:57.065Z'],
			WgEasyClient::extractOneTimeLink([
				'id' => 10,
				'oneTimeLink' => [
					'id' => 10,
					'oneTimeLink' => 'aaec8ec',
					'expiresAt' => '2026-07-27T14:56:57.065Z',
				],
			])
		);
	}

	public function testExtractOneTimeLinkAcceptsLegacyStringAndAbsentRelation(): void
	{
		self::assertSame(['abc123', null], WgEasyClient::extractOneTimeLink(['oneTimeLink' => 'abc123']));
		self::assertSame([null, null], WgEasyClient::extractOneTimeLink(['oneTimeLink' => null]));
		// The single-client endpoint omits the key entirely.
		self::assertSame([null, null], WgEasyClient::extractOneTimeLink(['id' => 10]));
		self::assertSame([null, null], WgEasyClient::extractOneTimeLink(['oneTimeLink' => ['expiresAt' => 'x']]));
	}
}
