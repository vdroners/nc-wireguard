<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTime;
use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeEngine;
use OCA\NcWireguard\Service\NcOtlService;
use OCA\NcWireguard\Service\PeerConfBuilder;
use OCA\NcWireguard\Service\PeerIpam;
use OCA\NcWireguard\Service\PeerSecretCrypto;
use OCA\NcWireguard\Service\SecretCrypto;
use OCA\NcWireguard\Service\ServerKeyStore;
use OCA\NcWireguard\Service\WgSyncClient;
use OCA\NcWireguard\Service\WireGuardKeys;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerSecretMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryServerConfigMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\RecordingWgSyncClient;
use OCA\NcWireguard\Tests\Unit\Doubles\ReversibleCrypto;
use OCA\NcWireguard\Util\DockerUrlResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The native engine's two hard policies — refuse Amnezia, refuse IPv6 — are the
 * whole reason it is not a thin passthrough. Both are asserted to fail *before*
 * anything is written, because a peer that is half-applied and then rejected is
 * worse than one that was never accepted.
 */
final class NativeEngineTest extends TestCase
{
	private InMemoryPeerMapper $peers;
	private RecordingWgSyncClient $wgSync;

	public function testAmneziaFieldsAreRefusedNotDropped(): void
	{
		$engine = $this->engine();

		$result = $engine->create(['name' => 'obfuscated', 'jc' => 4]);

		self::assertFalse($result['ok']);
		self::assertSame(NativeEngine::ERR_AMNEZIA, $result['code']);
		self::assertSame(0, $this->peers->countAll(), 'nothing may be written on a refusal');
		self::assertSame([], $this->wgSync->applied);
	}

	public function testZeroedAmneziaFieldsAreNotTreatedAsPresent(): void
	{
		$result = $this->engine()->create(['name' => 'plain', 'jc' => 0, 'i1' => '']);

		self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
	}

	public function testIpv6AddressIsRefused(): void
	{
		$result = $this->engine()->create(['name' => 'dual-stack', 'ipv6Address' => 'fd00::2']);

		self::assertFalse($result['ok']);
		self::assertSame(NativeEngine::ERR_IPV6, $result['code']);
		self::assertSame(0, $this->peers->countAll());
	}

	public function testStoredAmneziaPeerIsExcludedFromTheAppliedSet(): void
	{
		$engine = $this->engine();
		$this->storePeer('clean', false);
		$this->storePeer('obfuscated', true);

		$result = $engine->syncToSidecar();

		self::assertTrue($result['ok']);
		$applied = $this->wgSync->applied[0]['peers'];
		self::assertCount(1, $applied);
		self::assertSame('clean', $applied[0]['name']);
	}

	public function testAppliedPeerRoutesTheTunnelAddressOnly(): void
	{
		$engine = $this->engine();
		$this->storePeer('field-1', false, '10.8.0.7/32');

		$engine->syncToSidecar();

		$peer = $this->wgSync->applied[0]['peers'][0];
		// AllowedIPs on the *server* side is what to route to this peer, which
		// is its own address — not the peer's own AllowedIPs (the other way).
		self::assertSame(['10.8.0.7/32'], $peer['allowed_ips']);
		self::assertSame(25, $peer['persistent_keepalive']);
	}

	public function testSyncRefusesWithoutAnInterfaceKey(): void
	{
		$engine = $this->engine(withServerKey: false);
		$this->storePeer('field-1', false);

		$result = $engine->syncToSidecar();

		self::assertFalse($result['ok']);
		self::assertSame(NativeEngine::ERR_NO_SERVER_KEY, $result['code']);
		self::assertSame([], $this->wgSync->applied);
	}

	public function testCreatedPeerGetsAnAddressAndSealedKeyMaterial(): void
	{
		$engine = $this->engine();

		$result = $engine->create(['name' => 'field-2']);

		self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
		$peer = $this->peers->findById((int) $result['clientId']);
		self::assertNotNull($peer);
		self::assertSame('10.8.0.2/32', $peer->getIpv4());
		self::assertTrue(WireGuardKeys::isValid((string) $peer->getPublicKey()));
	}

	public function testIdentifiesItselfAsNative(): void
	{
		self::assertSame('native', $this->engine()->getServerInfo()['engine']);
	}

	// --- fixtures -----------------------------------------------------------

	private function engine(bool $withServerKey = true): NativeEngine
	{
		$config = new ArrayConfig();
		$crypto = new PeerSecretCrypto(new ReversibleCrypto());

		$this->peers = new InMemoryPeerMapper();
		$secrets = new InMemoryPeerSecretMapper();

		$row = new ServerConfig();
		$row->setId(ServerConfig::SINGLETON_ID);
		$row->setHost('vpn.example.test');
		$row->setPort(51820);
		$row->setCidr('10.8.0.0/24');
		$row->setMtu(1420);
		$row->setIpv4Only(true);
		$server = new InMemoryServerConfigMapper($row);

		$confBuilder = new PeerConfBuilder($this->peers, $secrets, $server, $crypto);
		$serverKeys = new ServerKeyStore($config, $crypto, $server);
		if ($withServerKey) {
			$serverKeys->store(WireGuardKeys::generate()['private']);
		}

		$settings = new AppSettings(
			$config,
			new SecretCrypto(
				$config,
				new ReversibleCrypto(),
				$this->createMock(LoggerInterface::class)
			),
			new DockerUrlResolver($this->createMock(LoggerInterface::class))
		);
		$this->wgSync = new RecordingWgSyncClient($settings, $this->createMock(LoggerInterface::class));

		return new NativeEngine(
			$this->peers,
			$secrets,
			$server,
			$crypto,
			new PeerIpam($this->peers, $server),
			$confBuilder,
			$serverKeys,
			$this->wgSync,
			new NcOtlService(
				$config,
				$this->peers,
				$confBuilder,
				$this->createMock(LoggerInterface::class)
			),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function storePeer(string $name, bool $amnezia, string $ipv4 = '10.8.0.5/32'): Peer
	{
		$peer = new Peer();
		$peer->setUuid('uuid-' . $name);
		$peer->setPublicKey(WireGuardKeys::generate()['public']);
		$peer->setName($name);
		$peer->setEnabled(true);
		$peer->setIpv4($ipv4);
		$peer->setPersistentKeepalive(25);
		$peer->setHasAmnezia($amnezia);
		$peer->setCreatedAt(new DateTime());
		$peer->setUpdatedAt(new DateTime());

		/** @var Peer $stored */
		$stored = $this->peers->insert($peer);
		return $stored;
	}
}
