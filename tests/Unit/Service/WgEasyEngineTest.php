<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Service\ConnectionStateMachine;
use OCA\NcWireguard\Service\WgEasyClient;
use OCA\NcWireguard\Service\WgEasyEngine;
use OCA\NcWireguard\Service\WireGuardEngineInterface;
use PHPUnit\Framework\TestCase;

/**
 * The engine adapter must not change wg-easy behaviour — it only renames the
 * methods and adds the public-key-keyed runtime view.
 */
final class WgEasyEngineTest extends TestCase
{
	public function testImplementsTheEngineInterface(): void
	{
		self::assertInstanceOf(
			WireGuardEngineInterface::class,
			new WgEasyEngine($this->createMock(WgEasyClient::class), new ConnectionStateMachine())
		);
	}

	public function testReadsDelegateToTheWgEasyClient(): void
	{
		$client = $this->createMock(WgEasyClient::class);
		$client->expects(self::once())->method('getClients')->willReturn([['id' => 1]]);
		$client->expects(self::once())->method('getClient')->with(7)->willReturn(['id' => 7]);
		$client->expects(self::once())->method('getServerDefaults')->willReturn(['ok' => true]);
		$client->expects(self::once())->method('getClientConfiguration')->with(7)->willReturn([
			'ok' => true,
			'http_code' => 200,
			'body' => '[Interface]',
			'error' => '',
			'is_json' => false,
		]);
		$client->expects(self::once())->method('formatConfigurationBody')
			->with('[Interface]', false)
			->willReturn(['configuration' => '[Interface]']);

		$engine = new WgEasyEngine($client, new ConnectionStateMachine());

		self::assertSame([['id' => 1]], $engine->listPeers());
		self::assertSame(['id' => 7], $engine->getPeer(7));
		self::assertSame(['ok' => true], $engine->getServerInfo());
		self::assertSame('[Interface]', $engine->getConfiguration(7)['body']);
		self::assertSame(
			['configuration' => '[Interface]'],
			$engine->formatConfigurationBody('[Interface]', false)
		);
	}

	public function testWritesDelegateToTheWgEasyClient(): void
	{
		$ok = ['ok' => true, 'http_code' => 200];
		$client = $this->createMock(WgEasyClient::class);
		$client->expects(self::once())->method('createClient')
			->with(['name' => 'field', 'expiresAt' => null])
			->willReturn(['ok' => true, 'http_code' => 201, 'clientId' => 12]);
		$client->expects(self::once())->method('updateClient')->with(12, ['mtu' => 1420])->willReturn($ok);
		$client->expects(self::once())->method('enableClient')->with(12)->willReturn($ok);
		$client->expects(self::once())->method('disableClient')->with(12)->willReturn($ok);
		$client->expects(self::once())->method('deleteClient')->with(12)->willReturn($ok);
		$client->expects(self::once())->method('generateOneTimeLink')->with(12)->willReturn([
			'ok' => true,
			'http_code' => 200,
			'oneTimeLink' => 'abc123',
		]);
		$client->expects(self::once())->method('redeemOneTimeLink')->with('abc123')->willReturn([
			'ok' => true,
			'http_code' => 200,
			'body' => '[Interface]',
			'error' => '',
			'content_type' => 'text/plain',
		]);

		$engine = new WgEasyEngine($client, new ConnectionStateMachine());

		self::assertSame(12, $engine->create(['name' => 'field', 'expiresAt' => null])['clientId']);
		self::assertTrue($engine->update(12, ['mtu' => 1420])['ok']);
		self::assertTrue($engine->enable(12)['ok']);
		self::assertTrue($engine->disable(12)['ok']);
		self::assertTrue($engine->delete(12)['ok']);
		self::assertSame('abc123', $engine->generateOneTimeLink(12)['oneTimeLink']);
		self::assertSame('[Interface]', $engine->redeemOneTimeLink('abc123')['body']);
	}

	public function testRuntimeStatsAreKeyedByPublicKey(): void
	{
		$fresh = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$client = $this->createMock(WgEasyClient::class);
		$client->method('getClients')->willReturn([
			[
				'id' => 3,
				'name' => 'Takoradi GCS',
				'publicKey' => 'AAA=',
				'transferRx' => 111,
				'transferTx' => 222,
				'endpoint' => '203.0.113.5:51820',
				'latestHandshakeAt' => $fresh,
			],
			[
				'id' => 4,
				'name' => 'Stale peer',
				'publicKey' => 'BBB=',
				'transferRx' => 0,
				'transferTx' => 0,
				'endpoint' => null,
				'latestHandshakeAt' => '2020-01-01T00:00:00Z',
			],
		]);

		$stats = (new WgEasyEngine($client, new ConnectionStateMachine()))->getRuntimeStats();

		self::assertSame(['AAA=', 'BBB='], array_keys($stats));
		self::assertSame([
			'public_key' => 'AAA=',
			'transfer_rx' => 111,
			'transfer_tx' => 222,
			'endpoint' => '203.0.113.5:51820',
			'latest_handshake' => $fresh,
			'connected' => true,
			'peer_id' => 3,
			'name' => 'Takoradi GCS',
		], $stats['AAA=']);
		self::assertFalse($stats['BBB=']['connected']);
		self::assertNull($stats['BBB=']['endpoint']);
		self::assertSame(4, $stats['BBB=']['peer_id']);
	}

	public function testRuntimeStatsAcceptSnakeCaseAndSkipKeylessPeers(): void
	{
		$client = $this->createMock(WgEasyClient::class);
		$client->method('getClients')->willReturn([
			[
				'peer_id' => 9,
				'public_key' => 'CCC=',
				'transfer_rx' => 5,
				'transfer_tx' => 6,
				'latest_handshake' => '2020-01-01T00:00:00Z',
			],
			// A peer with no public key has no engine-agnostic identity.
			['id' => 10, 'name' => 'keyless', 'transferRx' => 1],
			['id' => 11, 'publicKey' => '', 'name' => 'empty key'],
		]);

		$stats = (new WgEasyEngine($client, new ConnectionStateMachine()))->getRuntimeStats();

		self::assertSame(['CCC='], array_keys($stats));
		self::assertSame(9, $stats['CCC=']['peer_id']);
		self::assertSame(5, $stats['CCC=']['transfer_rx']);
		self::assertSame(6, $stats['CCC=']['transfer_tx']);
		self::assertSame('2020-01-01T00:00:00Z', $stats['CCC=']['latest_handshake']);
	}

	public function testRuntimeStatsAreEmptyWhenTheEngineIsUnreachable(): void
	{
		$client = $this->createMock(WgEasyClient::class);
		$client->method('getClients')->willReturn(null);

		self::assertSame([], (new WgEasyEngine($client, new ConnectionStateMachine()))->getRuntimeStats());
	}
}
