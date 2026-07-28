<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Service\WireGuardEngineInterface;
use OCA\NcWireguard\Tests\Unit\FakeWireGuardEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine contract itself: the fake is a full second implementation, so
 * anything it cannot satisfy is a leak of wg-easy specifics into the interface.
 */
final class FakeWireGuardEngineTest extends TestCase
{
	public function testFakeImplementsEveryInterfaceMethod(): void
	{
		$fake = new FakeWireGuardEngine();
		self::assertInstanceOf(WireGuardEngineInterface::class, $fake);

		$declared = get_class_methods(WireGuardEngineInterface::class);
		self::assertNotEmpty($declared);
		foreach ($declared as $method) {
			self::assertTrue(method_exists($fake, $method), "fake engine is missing {$method}()");
		}
	}

	public function testCrudRoundTrip(): void
	{
		$fake = new FakeWireGuardEngine();
		self::assertSame([], $fake->listPeers());

		$created = $fake->create(['name' => 'field-peer', 'expiresAt' => null]);
		self::assertTrue($created['ok']);
		$peerId = (int) $created['clientId'];

		$peer = $fake->getPeer($peerId);
		self::assertIsArray($peer);
		self::assertSame('field-peer', $peer['name']);
		self::assertTrue($peer['enabled']);

		self::assertTrue($fake->update($peerId, ['name' => 'renamed'])['ok']);
		self::assertSame('renamed', $fake->getPeer($peerId)['name']);

		self::assertTrue($fake->disable($peerId)['ok']);
		self::assertFalse($fake->getPeer($peerId)['enabled']);
		self::assertTrue($fake->enable($peerId)['ok']);
		self::assertTrue($fake->getPeer($peerId)['enabled']);

		self::assertStringContainsString('[Interface]', (string) $fake->getConfiguration($peerId)['body']);

		self::assertTrue($fake->delete($peerId)['ok']);
		self::assertNull($fake->getPeer($peerId));
		self::assertSame([], $fake->listPeers());
	}

	public function testMissingPeerYieldsNotFoundEnvelopes(): void
	{
		$fake = new FakeWireGuardEngine();
		foreach (['update', 'delete', 'enable', 'disable', 'getConfiguration', 'generateOneTimeLink'] as $method) {
			$result = $method === 'update' ? $fake->update(404, []) : $fake->{$method}(404);
			self::assertFalse($result['ok'], "{$method}() should fail for a missing peer");
			self::assertSame(404, $result['http_code']);
		}
	}

	public function testRuntimeStatsAreKeyedByPublicKey(): void
	{
		$fresh = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$fake = new FakeWireGuardEngine([
			[
				'id' => 2,
				'name' => 'GCS',
				'publicKey' => 'XYZ=',
				'transferRx' => 10,
				'transferTx' => 20,
				'endpoint' => '198.51.100.7:51820',
				'latestHandshakeAt' => $fresh,
			],
		]);

		$stats = $fake->getRuntimeStats();

		self::assertSame(['XYZ='], array_keys($stats));
		$entry = $stats['XYZ='];
		self::assertSame('XYZ=', $entry['public_key']);
		self::assertSame(2, $entry['peer_id']);
		self::assertSame(10, $entry['transfer_rx']);
		self::assertSame(20, $entry['transfer_tx']);
		self::assertSame('198.51.100.7:51820', $entry['endpoint']);
		self::assertTrue($entry['connected']);
	}

	public function testOneTimeLinkIsSingleUse(): void
	{
		$fake = new FakeWireGuardEngine();
		$peerId = (int) $fake->create(['name' => 'otl', 'expiresAt' => null])['clientId'];

		$minted = $fake->generateOneTimeLink($peerId);
		self::assertTrue($minted['ok']);
		$token = (string) $minted['oneTimeLink'];

		$first = $fake->redeemOneTimeLink($token);
		self::assertTrue($first['ok']);
		self::assertStringContainsString('[Interface]', (string) $first['body']);

		$second = $fake->redeemOneTimeLink($token);
		self::assertFalse($second['ok']);
		self::assertSame(404, $second['http_code']);
	}

	public function testUnreachableEngineReportsNoPeers(): void
	{
		$fake = new FakeWireGuardEngine([['id' => 1, 'publicKey' => 'AAA=']]);
		$fake->setReachable(false);

		self::assertNull($fake->listPeers());
		self::assertSame([], $fake->getRuntimeStats());
	}
}
