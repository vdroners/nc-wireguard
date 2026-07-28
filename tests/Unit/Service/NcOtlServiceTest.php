<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerSecret;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Service\NcOtlService;
use OCA\NcWireguard\Service\PeerConfBuilder;
use OCA\NcWireguard\Service\PeerSecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerSecretMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryServerConfigMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\ReversibleCrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * NC-minted one-time links (`otl_source=nc`). The security property under test
 * is single use: a redeemed token must never render a second copy of the key
 * material, even though the same route is public and unauthenticated.
 */
final class NcOtlServiceTest extends TestCase
{
	private InMemoryPeerMapper $peers;

	private InMemoryPeerSecretMapper $secrets;

	protected function setUp(): void
	{
		$this->peers = new InMemoryPeerMapper();
		$this->secrets = new InMemoryPeerSecretMapper();
	}

	public function testMintThenRedeemReturnsTheRenderedConfig(): void
	{
		$service = $this->service();
		$peer = $this->storedPeer(7);

		$minted = $service->mint($peer);
		self::assertTrue($minted['ok']);
		self::assertNotEmpty($minted['oneTimeLink']);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $minted['oneTimeLink']);

		$redeemed = $service->redeem($minted['oneTimeLink']);

		self::assertTrue($redeemed['ok']);
		self::assertStringContainsString('PrivateKey = PEER-PRIVATE-KEY', (string) $redeemed['body']);
		self::assertStringContainsString('Endpoint = vpn.example.org:51820', (string) $redeemed['body']);
		self::assertSame('field-1.conf', $redeemed['filename']);
	}

	public function testASecondRedeemOfTheSameTokenFails(): void
	{
		$service = $this->service();
		$token = $service->mint($this->storedPeer(7))['oneTimeLink'];

		self::assertTrue($service->redeem($token)['ok']);

		$replay = $service->redeem($token);
		self::assertFalse($replay['ok']);
		self::assertSame(404, $replay['http_code']);
		self::assertSame(NcOtlService::ERR_UNKNOWN, $replay['code']);
	}

	public function testAnExpiredTokenIsReportedAsExpiredNotUnknown(): void
	{
		$config = new ArrayConfig();
		$service = $this->service($config);
		$peer = $this->storedPeer(7);
		$token = $service->mint($peer)['oneTimeLink'];

		// Age the stored entry past its TTL without waiting five minutes.
		$config->setAppValue('nc_wireguard', 'nc_otl_tokens', (string) json_encode([
			$token => ['uuid' => $peer->getUuid(), 'exp' => time() - 1],
		]));

		$result = $service->redeem($token);

		self::assertFalse($result['ok']);
		self::assertSame(410, $result['http_code']);
		self::assertSame(NcOtlService::ERR_EXPIRED, $result['code']);
	}

	public function testUnknownTokenIsFlaggedSoTheControllerCanFallBackToTheEngine(): void
	{
		$result = $this->service()->redeem('never-minted');

		self::assertFalse($result['ok']);
		self::assertSame(NcOtlService::ERR_UNKNOWN, $result['code']);
	}

	public function testMintResolvesAWgEasyIdSoPreCutoverLinksKeepWorking(): void
	{
		$service = $this->service();
		$this->storedPeer(7);

		$minted = $service->mintForEngineId(7);

		self::assertTrue($minted['ok']);
	}

	public function testMintForAnUnknownIdIsARefusalNotASilentEmptyLink(): void
	{
		$minted = $this->service()->mintForEngineId(404);

		self::assertFalse($minted['ok']);
		self::assertSame(404, $minted['http_code']);
	}

	public function testMintRefusesAPeerWhoseConfigCannotBeBuilt(): void
	{
		$service = $this->service();
		$peer = $this->storedPeer(7);
		// Key material never made it through the import.
		$this->secrets->deleteByPeerId((int) $peer->getId());

		$minted = $service->mint($peer);

		self::assertFalse($minted['ok']);
		self::assertSame(422, $minted['http_code']);
	}

	public function testRevokeAllDropsOutstandingTokens(): void
	{
		$service = $this->service();
		$token = $service->mint($this->storedPeer(7))['oneTimeLink'];

		$service->revokeAll();

		self::assertFalse($service->redeem($token)['ok']);
	}

	private function service(?ArrayConfig $config = null): NcOtlService
	{
		$crypto = new PeerSecretCrypto(new ReversibleCrypto());
		$server = new ServerConfig();
		$server->setHost('vpn.example.org');
		$server->setPort(51820);
		$server->setServerPublicKey('SERVER-PUBLIC-KEY');
		$server->setIpv4Only(true);
		$server->setDefaultAllowedIps('10.0.0.0/24, 10.8.0.0/24');
		$server->setDefaultKeepalive(25);

		return new NcOtlService(
			$config ?? new ArrayConfig(),
			$this->peers,
			new PeerConfBuilder(
				$this->peers,
				$this->secrets,
				new InMemoryServerConfigMapper($server),
				$crypto
			),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function storedPeer(int $wgEasyId): Peer
	{
		$peer = new Peer();
		$peer->setUuid('uuid-' . $wgEasyId);
		$peer->setPublicKey('PEER-PUBLIC-KEY');
		$peer->setWgEasyId($wgEasyId);
		$peer->setName('field-1');
		$peer->setEnabled(true);
		$peer->setIpv4('10.8.0.5/32');
		$peer->setMtu(1420);
		$this->peers->insert($peer);

		$secret = new PeerSecret();
		$secret->setPeerId((int) $peer->getId());
		$secret->setPrivateKeyEnc(
			(new PeerSecretCrypto(new ReversibleCrypto()))->encrypt('PEER-PRIVATE-KEY')
		);
		$this->secrets->save($secret, false);

		return $peer;
	}
}
