<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\PeerIpam;
use OCA\NcWireguard\Service\PeerSecretCrypto;
use OCA\NcWireguard\Service\PeerStoreService;
use OCA\NcWireguard\Service\SecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerSecretMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryServerConfigMapper;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shadow-store behaviour: peers land in NC keyed by public key, secrets are
 * sealed, and nothing about the live engine changes.
 */
final class PeerStoreServiceTest extends TestCase
{
	private InMemoryPeerMapper $peers;
	private InMemoryPeerSecretMapper $secrets;
	private ArrayConfig $config;
	private PeerSecretCrypto $crypto;

	protected function setUp(): void
	{
		parent::setUp();
		$this->peers = new InMemoryPeerMapper();
		$this->secrets = new InMemoryPeerSecretMapper();
		$this->config = new ArrayConfig();
		$this->crypto = new PeerSecretCrypto($this->reversibleCrypto());
	}

	public function testImportsTwoEnginePeersAndSealsTheirKeys(): void
	{
		$store = $this->store();

		$field = $store->upsertFromEngine([
			'id' => 4,
			'name' => 'Takoradi GCS',
			'publicKey' => 'pub-field=',
			'privateKey' => 'priv-field=',
			'preSharedKey' => 'psk-field=',
			'enabled' => true,
			'ipv4Address' => '10.8.0.4',
			'allowedIps' => ['0.0.0.0/0'],
			'dns' => ['10.8.0.1'],
			'mtu' => 1420,
			'persistentKeepalive' => 25,
		]);
		$server = $store->upsertFromEngine([
			'id' => 5,
			'name' => 'Server',
			'publicKey' => 'pub-server=',
			'privateKey' => 'priv-server=',
			'enabled' => true,
			'ipv4Address' => '10.8.0.2',
			'persistentKeepalive' => 0,
		]);

		self::assertCount(2, $this->peers->findAll());
		self::assertSame(4, $field->getWgEasyId());
		self::assertSame('10.8.0.4/32', $field->getIpv4());
		self::assertSame('0.0.0.0/0', $field->getAllowedIps());
		self::assertSame('10.8.0.1', $field->getDns());
		self::assertSame(1420, $field->getMtu());
		self::assertSame(25, $field->getPersistentKeepalive());
		self::assertTrue($field->getEnabled());
		self::assertFalse($field->getHasAmnezia());
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $field->getUuid()
		);
		self::assertNotSame($field->getUuid(), $server->getUuid());

		$secret = $this->secrets->findByPeerId((int) $field->getId());
		self::assertNotNull($secret);
		self::assertStringStartsWith(PeerSecretCrypto::PREFIX, $secret->getPrivateKeyEnc());
		self::assertStringNotContainsString('priv-field=', $secret->getPrivateKeyEnc());
		self::assertSame('priv-field=', $this->crypto->decrypt($secret->getPrivateKeyEnc()));
		self::assertSame('psk-field=', $this->crypto->decryptOptional($secret->getPskEnc()));

		// The break-glass peer has no PSK; the column stays null rather than sealing ''.
		$serverSecret = $this->secrets->findByPeerId((int) $server->getId());
		self::assertNotNull($serverSecret);
		self::assertNull($serverSecret->getPskEnc());
	}

	public function testReimportUpdatesInPlaceAndKeepsStoredKeyMaterial(): void
	{
		$store = $this->store();
		$first = $store->upsertFromEngine([
			'id' => 4,
			'name' => 'Takoradi GCS',
			'publicKey' => 'pub-field=',
			'privateKey' => 'priv-field=',
			'ipv4Address' => '10.8.0.4',
		]);

		$second = $store->upsertFromEngine([
			'id' => 4,
			'name' => 'Takoradi GCS (renamed)',
			'publicKey' => 'pub-field=',
			'privateKey' => 'stale-export-key=',
			'ipv4Address' => '10.8.0.4',
		]);

		self::assertCount(1, $this->peers->findAll());
		self::assertSame($first->getUuid(), $second->getUuid());
		self::assertSame('Takoradi GCS (renamed)', $second->getName());
		self::assertSame(
			'priv-field=',
			$this->crypto->decrypt($this->secrets->findByPeerId((int) $second->getId())->getPrivateKeyEnc()),
			'a re-import must not silently replace good key material'
		);
	}

	public function testKeyRewriteRequiresTheExplicitFlag(): void
	{
		$store = $this->store();
		$store->upsertFromEngine([
			'id' => 4,
			'name' => 'Field',
			'publicKey' => 'pub-field=',
			'privateKey' => 'priv-field=',
			'ipv4Address' => '10.8.0.4',
		]);

		$peer = $store->upsertFromEngine([
			'id' => 4,
			'name' => 'Field',
			'publicKey' => 'pub-field=',
			'privateKey' => 'rotated-key=',
			'ipv4Address' => '10.8.0.4',
		], true);

		self::assertSame(
			'rotated-key=',
			$this->crypto->decrypt($this->secrets->findByPeerId((int) $peer->getId())->getPrivateKeyEnc())
		);
	}

	public function testPeerWithoutKeyMaterialIsStoredWithoutASecretRow(): void
	{
		$peer = $this->store()->upsertFromEngine([
			'id' => 7,
			'name' => 'From list endpoint',
			'publicKey' => 'pub-listonly=',
			'ipv4Address' => '10.8.0.7',
		]);

		self::assertNull($this->secrets->findByPeerId((int) $peer->getId()));
		self::assertSame(0, $this->secrets->saveCalls);
	}

	public function testPublicKeyIsRequired(): void
	{
		$this->expectException(RuntimeException::class);
		$this->store()->upsertFromEngine(['id' => 1, 'name' => 'keyless']);
	}

	public function testIpamFillsTheGapWhenTheSourceHasNoAddress(): void
	{
		$store = $this->store();
		$store->upsertFromEngine([
			'id' => 1,
			'name' => 'First',
			'publicKey' => 'pub-1=',
			'ipv4Address' => '10.8.0.2',
		]);

		$second = $store->upsertFromEngine([
			'id' => 2,
			'name' => 'Second',
			'publicKey' => 'pub-2=',
		]);

		self::assertSame('10.8.0.3/32', $second->getIpv4());
	}

	public function testBreakGlassPeerIsNeverReAddressedByIpam(): void
	{
		$peer = $this->store()->upsertFromEngine([
			'id' => 5,
			'name' => 'Server',
			'publicKey' => 'pub-server=',
		]);

		self::assertNull($peer->getIpv4(), 'the break-glass peer keeps the engine assignment');
		self::assertSame([], $this->peers->findAssignedIpv4());
	}

	public function testAmneziaFlagSurvivesImport(): void
	{
		$peer = $this->store()->upsertFromEngine([
			'id' => 9,
			'name' => 'Obfuscated',
			'publicKey' => 'pub-amnezia=',
			'ipv4Address' => '10.8.0.9',
			'jc' => 4,
		]);

		self::assertTrue($peer->getHasAmnezia());
	}

	public function testShadowModeFollowsTheEngineSetting(): void
	{
		$store = $this->store();
		self::assertTrue($store->isShadowMode(), 'wgeasy is the default engine');

		$this->config->setAppValue('nc_wireguard', 'engine', AppSettings::ENGINE_NATIVE);
		self::assertFalse($store->isShadowMode());
	}

	private function store(): PeerStoreService
	{
		$settings = new AppSettings(
			$this->config,
			new SecretCrypto(
				$this->config,
				$this->createMock(ICrypto::class),
				$this->createMock(LoggerInterface::class)
			),
			new DockerUrlResolver($this->createMock(LoggerInterface::class))
		);

		return new PeerStoreService(
			$this->peers,
			$this->secrets,
			$this->crypto,
			new PeerIpam($this->peers, new InMemoryServerConfigMapper()),
			$settings,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function reversibleCrypto(): ICrypto
	{
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			static fn (string $data): string => base64_encode($data)
		);
		$crypto->method('decrypt')->willReturnCallback(
			static fn (string $data): string => (string) base64_decode($data, true)
		);
		return $crypto;
	}
}
