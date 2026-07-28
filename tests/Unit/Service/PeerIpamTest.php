<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Service\PeerIpam;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryServerConfigMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PeerIpamTest extends TestCase
{
	public function testFirstAllocationSkipsTheReservedServerAddress(): void
	{
		self::assertSame('10.8.0.2/32', $this->ipam()->nextFree('10.8.0.0/24', []));
	}

	public function testFillsTheFirstGapAndIgnoresPrefixNotation(): void
	{
		$taken = ['10.8.0.2/32', '10.8.0.3', '10.8.0.5/32'];

		self::assertSame('10.8.0.4/32', $this->ipam()->nextFree('10.8.0.0/24', $taken));
	}

	public function testServerAddressIsTheFirstHostInThePool(): void
	{
		$ipam = $this->ipam();

		self::assertSame('10.8.0.1', $ipam->serverAddress('10.8.0.0/24'));
		self::assertSame('10.9.4.1', $ipam->serverAddress('10.9.4.0/22'));
	}

	public function testExplicitlyTakenServerAddressIsNotHandedOut(): void
	{
		$allocated = $this->ipam()->nextFree('10.8.0.0/24', ['10.8.0.1/32']);

		self::assertSame('10.8.0.2/32', $allocated);
	}

	public function testNonPoolAddressesDoNotBlockAllocation(): void
	{
		// A stale entry from another subnet must not consume a slot here.
		$allocated = $this->ipam()->nextFree('10.8.0.0/24', ['192.168.9.2/32', 'not-an-ip']);

		self::assertSame('10.8.0.2/32', $allocated);
	}

	public function testBroadcastAndNetworkAddressesAreNeverAllocated(): void
	{
		$taken = [];
		for ($host = 2; $host <= 253; $host++) {
			$taken[] = '10.8.0.' . $host . '/32';
		}

		self::assertSame('10.8.0.254/32', $this->ipam()->nextFree('10.8.0.0/24', $taken));

		$taken[] = '10.8.0.254/32';
		$this->expectException(RuntimeException::class);
		$this->ipam()->nextFree('10.8.0.0/24', $taken);
	}

	public function testMalformedOrOversizedPoolsAreRejected(): void
	{
		$ipam = $this->ipam();

		foreach (['10.8.0.0', 'nonsense/24', '10.8.0.0/8', '10.8.0.0/31', '::/0'] as $cidr) {
			try {
				$ipam->nextFree($cidr, []);
				self::fail('Expected ' . $cidr . ' to be rejected');
			} catch (RuntimeException) {
				self::assertTrue(true);
			}
		}
	}

	public function testAllocateReadsThePoolFromTheServerRowAndAvoidsStoredPeers(): void
	{
		$peers = new InMemoryPeerMapper();
		$peers->insert($this->peer('10.9.0.2/32'));
		$peers->insert($this->peer('10.9.0.3/32'));

		$server = new ServerConfig();
		$server->setCidr('10.9.0.0/24');

		$ipam = new PeerIpam($peers, new InMemoryServerConfigMapper($server));

		self::assertSame('10.9.0.0/24', $ipam->poolCidr());
		self::assertSame('10.9.0.4/32', $ipam->allocate());
	}

	public function testPoolFallsBackToTheWgEasyDefaultWithoutAServerRow(): void
	{
		$ipam = $this->ipam();

		self::assertSame(PeerIpam::DEFAULT_CIDR, $ipam->poolCidr());
		self::assertSame('10.8.0.2/32', $ipam->allocate());
	}

	public function testIsInPool(): void
	{
		$ipam = $this->ipam();

		self::assertTrue($ipam->isInPool('10.8.0.7/32', '10.8.0.0/24'));
		self::assertFalse($ipam->isInPool('10.9.0.7', '10.8.0.0/24'));
		self::assertFalse($ipam->isInPool('garbage', '10.8.0.0/24'));
	}

	private function ipam(): PeerIpam
	{
		return new PeerIpam(new InMemoryPeerMapper(), new InMemoryServerConfigMapper());
	}

	private function peer(string $ipv4): Peer
	{
		$peer = new Peer();
		$peer->setPublicKey('pk-' . $ipv4);
		$peer->setName('peer ' . $ipv4);
		$peer->setIpv4($ipv4);
		return $peer;
	}
}
