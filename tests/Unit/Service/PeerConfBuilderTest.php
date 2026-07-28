<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Service\PeerConfBuilder;
use OCA\NcWireguard\Service\PeerConfException;
use OCA\NcWireguard\Service\PeerPresets;
use OCA\NcWireguard\Service\PeerSecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerSecretMapper;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryServerConfigMapper;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The `.conf` is the artefact a field user actually installs, so the preset
 * behaviour is asserted line by line: a config that looks plausible but routes
 * nothing is the failure mode this builder exists to prevent.
 */
final class PeerConfBuilderTest extends TestCase
{
	public function testFieldPresetProducesASplitTunnelWithKeepalive(): void
	{
		$conf = $this->builder()->render(
			$this->peer(PeerPresets::PRESET_FIELD),
			'PRIVATE-KEY',
			null,
			$this->server()
		);

		self::assertSame(
			"[Interface]\n"
			. "PrivateKey = PRIVATE-KEY\n"
			. "Address = 10.8.0.5/32\n"
			. "MTU = 1420\n"
			. "\n"
			. "[Peer]\n"
			. "PublicKey = SERVER-PUBLIC-KEY\n"
			. "AllowedIPs = 10.0.0.0/24, 10.8.0.0/24\n"
			. "Endpoint = vpn.example.org:51820\n"
			. "PersistentKeepalive = 25\n",
			$conf
		);
	}

	public function testAdminPresetIsAFullTunnelWithDns(): void
	{
		$conf = $this->builder()->render(
			$this->peer(PeerPresets::PRESET_ADMIN),
			'PRIVATE-KEY',
			null,
			$this->server()
		);

		self::assertStringContainsString("DNS = 1.1.1.1\n", $conf);
		self::assertStringContainsString("AllowedIPs = 0.0.0.0/0\n", $conf);
		self::assertStringContainsString("PersistentKeepalive = 25\n", $conf);
	}

	public function testIpv4OnlyStripsIpv6RoutesIncludingTheDefaultRoute(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_ADMIN);
		$peer->setAllowedIps('0.0.0.0/0, ::/0');
		$peer->setDns('1.1.1.1, 2606:4700:4700::1111');

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());

		self::assertStringNotContainsString('::/0', $conf);
		self::assertStringContainsString("AllowedIPs = 0.0.0.0/0\n", $conf);
		self::assertStringContainsString("DNS = 1.1.1.1\n", $conf);
	}

	public function testAnAllowedIpsListThatIsEntirelyIpv6IsRefused(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setAllowedIps('::/0');

		$this->expectException(PeerConfException::class);
		$this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());
	}

	public function testPeerFieldsWinOverServerDefaults(): void
	{
		$server = $this->server();
		$server->setDefaultAllowedIps('10.8.0.0/24');
		$server->setDefaultDns('9.9.9.9');
		$server->setDefaultKeepalive(25);
		$server->setMtu(1420);

		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setAllowedIps('192.168.50.0/24');
		$peer->setDns('192.168.50.1');
		$peer->setMtu(1280);
		$peer->setPersistentKeepalive(15);

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $server);

		self::assertStringContainsString("AllowedIPs = 192.168.50.0/24\n", $conf);
		self::assertStringContainsString("DNS = 192.168.50.1\n", $conf);
		self::assertStringContainsString("MTU = 1280\n", $conf);
		self::assertStringContainsString("PersistentKeepalive = 15\n", $conf);
	}

	public function testServerDefaultsFillTheGapsAnImportLeftBehind(): void
	{
		$server = $this->server();
		$server->setDefaultAllowedIps('10.0.0.0/24, 10.8.0.0/24');
		$server->setDefaultDns('10.8.0.1');
		$server->setDefaultKeepalive(25);

		// Imported straight from wg-easy: no per-peer tunnel fields at all.
		$peer = new Peer();
		$peer->setUuid('uuid-bare');
		$peer->setName('bare');
		$peer->setIpv4('10.8.0.9/32');

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $server);

		self::assertStringContainsString("Address = 10.8.0.9/32\n", $conf);
		self::assertStringContainsString("AllowedIPs = 10.0.0.0/24, 10.8.0.0/24\n", $conf);
		self::assertStringContainsString("DNS = 10.8.0.1\n", $conf);
		self::assertStringContainsString("PersistentKeepalive = 25\n", $conf);
	}

	public function testKeepaliveZeroIsOmittedRatherThanWrittenAsOff(): void
	{
		$server = $this->server();
		$server->setDefaultKeepalive(0);
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setPersistentKeepalive(0);

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $server);

		self::assertStringNotContainsString('PersistentKeepalive', $conf);
	}

	public function testPresharedKeyIsEmittedWhenStored(): void
	{
		$conf = $this->builder()->render(
			$this->peer(PeerPresets::PRESET_FIELD),
			'PRIVATE-KEY',
			'PSK-VALUE',
			$this->server()
		);

		self::assertStringContainsString("PresharedKey = PSK-VALUE\n", $conf);
	}

	public function testServerEndpointOverrideWinsAndGainsThePortWhenBare(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setServerEndpoint('field.example.net');

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());

		self::assertStringContainsString("Endpoint = field.example.net:51820\n", $conf);
	}

	public function testServerEndpointOverrideKeepsAnExplicitPort(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setServerEndpoint('field.example.net:51830');

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());

		self::assertStringContainsString("Endpoint = field.example.net:51830\n", $conf);
	}

	public function testMissingPrivateKeyIsRefused(): void
	{
		$this->expectException(PeerConfException::class);
		$this->builder()->render($this->peer(PeerPresets::PRESET_FIELD), '  ', null, $this->server());
	}

	public function testMissingServerPublicKeyIsRefused(): void
	{
		$server = $this->server();
		$server->setServerPublicKey(null);

		$this->expectException(PeerConfException::class);
		$this->builder()->render($this->peer(PeerPresets::PRESET_FIELD), 'PRIVATE-KEY', null, $server);
	}

	public function testMissingEndpointIsRefused(): void
	{
		$server = $this->server();
		$server->setHost(null);

		$this->expectException(PeerConfException::class);
		$this->builder()->render($this->peer(PeerPresets::PRESET_FIELD), 'PRIVATE-KEY', null, $server);
	}

	public function testMissingTunnelAddressIsRefused(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setIpv4(null);

		$this->expectException(PeerConfException::class);
		$this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());
	}

	public function testPoolPrefixOnTheStoredAddressIsNarrowedToASingleHost(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setIpv4('10.8.0.5/24');

		$conf = $this->builder()->render($peer, 'PRIVATE-KEY', null, $this->server());

		self::assertStringContainsString("Address = 10.8.0.5/32\n", $conf);
	}

	public function testFilenameIsDerivedFromTheName(): void
	{
		$peer = $this->peer(PeerPresets::PRESET_FIELD);
		$peer->setName('Field GCS / trailer #2');

		self::assertSame('Field-GCS-trailer-2.conf', $this->builder()->filenameFor($peer));
	}

	private function builder(): PeerConfBuilder
	{
		return new PeerConfBuilder(
			new InMemoryPeerMapper(),
			new InMemoryPeerSecretMapper(),
			new InMemoryServerConfigMapper(),
			new PeerSecretCrypto($this->createMock(ICrypto::class))
		);
	}

	/**
	 * @param array{allowed_ips: string, dns: string|null, mtu: int, persistent_keepalive: int} $preset
	 */
	private function peer(array $preset): Peer
	{
		$peer = new Peer();
		$peer->setUuid('uuid-1');
		$peer->setPublicKey('PEER-PUBLIC-KEY');
		$peer->setName('field-1');
		$peer->setEnabled(true);
		$peer->setIpv4('10.8.0.5/32');
		$peer->setAllowedIps($preset['allowed_ips']);
		$peer->setDns($preset['dns']);
		$peer->setMtu($preset['mtu']);
		$peer->setPersistentKeepalive($preset['persistent_keepalive']);
		return $peer;
	}

	private function server(): ServerConfig
	{
		$server = new ServerConfig();
		$server->setHost('vpn.example.org');
		$server->setPort(51820);
		$server->setCidr('10.8.0.0/24');
		$server->setServerPublicKey('SERVER-PUBLIC-KEY');
		$server->setIpv4Only(true);
		return $server;
	}
}
