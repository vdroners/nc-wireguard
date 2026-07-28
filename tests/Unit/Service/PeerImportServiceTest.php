<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\PeerImportService;
use OCA\NcWireguard\Tests\Unit\FakeWireGuardEngine;
use OCA\NcWireguard\Util\EnginePeerRow;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Import of a two-peer estate — one ordinary Field peer plus the `Server`
 * break-glass peer — from both supported sources.
 */
final class PeerImportServiceTest extends TestCase
{
	/** @var list<string> */
	private array $cleanup = [];

	protected function tearDown(): void
	{
		foreach ($this->cleanup as $dir) {
			foreach (['peers', 'conf'] as $sub) {
				foreach (glob($dir . '/' . $sub . '/*') ?: [] as $file) {
					@unlink($file);
				}
				@rmdir($dir . '/' . $sub);
			}
			@rmdir($dir);
		}
		$this->cleanup = [];
		parent::tearDown();
	}

	public function testImportsTwoPeersFromTheLiveEngineWithSecretsFromGetOne(): void
	{
		$plan = $this->service($this->engine())->planFromEngine();

		self::assertSame('engine', $plan['source']);
		self::assertSame([], $plan['skipped']);
		self::assertCount(2, $plan['rows']);

		[$field, $server] = $plan['rows'];

		self::assertSame('Takoradi GCS', $field['name']);
		self::assertSame('pub-field=', $field['public_key']);
		self::assertSame(4, $field['wg_easy_id']);
		self::assertSame('10.8.0.4/32', $field['ipv4']);
		self::assertSame(25, $field['persistent_keepalive']);
		self::assertSame('0.0.0.0/0', $field['allowed_ips']);
		self::assertSame('10.8.0.1', $field['dns']);
		self::assertSame(1420, $field['mtu']);
		self::assertFalse($field['break_glass']);
		self::assertFalse($field['has_amnezia']);
		// listPeers() omits key material, so these can only come from get-one.
		self::assertSame('priv-field=', $field['private_key']);
		self::assertSame('psk-field=', $field['psk']);

		self::assertSame('Server', $server['name']);
		self::assertTrue($server['break_glass'], 'the Server peer must be flagged as break-glass');
		self::assertContains(
			'break-glass full-tunnel peer — preserved as-is',
			$server['notes']
		);
	}

	public function testKeepaliveZeroIsFlaggedAgainstTheFieldPreset(): void
	{
		$plan = $this->service($this->engine())->planFromEngine();
		$server = $plan['rows'][1];

		self::assertSame(0, $server['persistent_keepalive']);
		self::assertNotEmpty(array_filter(
			$server['notes'],
			static fn (string $note): bool => str_contains($note, 'keepalive=0')
				&& str_contains($note, (string) EnginePeerRow::FIELD_KEEPALIVE)
		));
	}

	public function testAmneziaAndMissingKeysAreFlaggedNotDropped(): void
	{
		$engine = new FakeWireGuardEngine([
			[
				'id' => 9,
				'name' => 'Obfuscated',
				'publicKey' => 'pub-amnezia=',
				'enabled' => true,
				'ipv4Address' => '10.8.0.9',
				'persistentKeepalive' => 25,
				'jc' => 4,
			],
		]);

		$row = $this->service($engine)->planFromEngine()['rows'][0];

		self::assertTrue($row['has_amnezia']);
		self::assertNull($row['private_key']);
		self::assertNotEmpty(array_filter(
			$row['notes'],
			static fn (string $note): bool => str_contains($note, 'Amnezia')
		));
		self::assertNotEmpty(array_filter(
			$row['notes'],
			static fn (string $note): bool => str_contains($note, 'no private key')
		));
	}

	public function testPeersWithoutAPublicKeyAreSkippedWithAReason(): void
	{
		$engine = new FakeWireGuardEngine([
			['id' => 1, 'name' => 'no key here', 'ipv4Address' => '10.8.0.3'],
		]);

		$plan = $this->service($engine)->planFromEngine();

		self::assertSame([], $plan['rows']);
		self::assertCount(1, $plan['skipped']);
		self::assertStringContainsString('no key here', $plan['skipped'][0]);
	}

	public function testUnreachableEngineFailsLoudly(): void
	{
		$engine = new FakeWireGuardEngine();
		$engine->setReachable(false);

		$this->expectException(RuntimeException::class);
		$this->service($engine)->planFromEngine();
	}

	public function testImportsTwoPeersFromAnExportDirectory(): void
	{
		$dir = $this->writeExport();

		$plan = $this->service($this->engine())->planFromExport($dir);

		self::assertStringStartsWith('export:', $plan['source']);
		self::assertCount(2, $plan['rows']);

		[$field, $server] = $plan['rows'];

		// The JSON carries no secrets; the .conf beside it does.
		self::assertSame('priv-from-conf=', $field['private_key']);
		self::assertSame('psk-from-conf=', $field['psk']);
		self::assertSame('10.8.0.4/32', $field['ipv4']);
		self::assertSame('0.0.0.0/0', $field['allowed_ips']);
		self::assertSame('10.8.0.1, 1.1.1.1', $field['dns']);
		self::assertSame(1420, $field['mtu']);
		self::assertSame(25, $field['persistent_keepalive']);
		self::assertSame('vpn.example.org:51820', $field['server_endpoint']);

		self::assertSame('Server', $server['name']);
		self::assertTrue($server['break_glass']);
		self::assertNull($server['private_key'], 'no .conf shipped for this peer');
	}

	public function testExportDirWithoutPeersSubdirFailsLoudly(): void
	{
		$dir = sys_get_temp_dir() . '/nc-wg-empty-' . bin2hex(random_bytes(4));
		mkdir($dir, 0700, true);

		$this->expectException(RuntimeException::class);
		try {
			$this->service($this->engine())->planFromExport($dir);
		} finally {
			rmdir($dir);
		}
	}

	public function testDescribeNeverLeaksKeyMaterial(): void
	{
		$row = $this->service($this->engine())->planFromEngine()['rows'][0];

		$view = PeerImportService::describe($row);

		self::assertSame('yes', $view['key']);
		self::assertSame('yes', $view['psk']);
		self::assertStringNotContainsString('priv-field=', implode('|', $view));
		self::assertStringNotContainsString('psk-field=', implode('|', $view));
		self::assertSame('pub-field=', $view['public_key'], 'short keys are shown whole');
		self::assertSame(
			'abcdefghij…',
			PeerImportService::fingerprint('abcdefghijklmnopqrstuvwxyz')
		);
	}

	private function service(FakeWireGuardEngine $engine): PeerImportService
	{
		return new PeerImportService($engine, $this->createMock(LoggerInterface::class));
	}

	private function engine(): FakeWireGuardEngine
	{
		return new FakeWireGuardEngine([
			[
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
			],
			[
				'id' => 5,
				'name' => 'Server',
				'publicKey' => 'pub-server=',
				'privateKey' => 'priv-server=',
				'enabled' => true,
				'ipv4Address' => '10.8.0.2',
				'allowedIps' => ['0.0.0.0/0'],
				'persistentKeepalive' => 0,
			],
		]);
	}

	/**
	 * Mirrors the layout scripts/export-peers.sh writes: peers/<id>.json plus
	 * conf/<id>.conf.
	 */
	private function writeExport(): string
	{
		$dir = sys_get_temp_dir() . '/nc-wg-export-' . bin2hex(random_bytes(4));
		mkdir($dir . '/peers', 0700, true);
		mkdir($dir . '/conf', 0700, true);

		file_put_contents($dir . '/peers/4.json', json_encode([
			'id' => 4,
			'name' => 'Takoradi GCS',
			'publicKey' => 'pub-field=',
			'enabled' => true,
			'ipv4Address' => '10.8.0.4',
			'persistentKeepalive' => 25,
		]));
		file_put_contents($dir . '/peers/5.json', json_encode([
			'id' => 5,
			'name' => 'Server',
			'publicKey' => 'pub-server=',
			'enabled' => true,
			'ipv4Address' => '10.8.0.2',
			'persistentKeepalive' => 0,
		]));
		file_put_contents($dir . '/conf/4.conf', <<<CONF
			# comment line
			[Interface]
			PrivateKey = priv-from-conf=
			Address = 10.8.0.4/24
			DNS = 10.8.0.1, 1.1.1.1
			MTU = 1420

			[Peer]
			PublicKey = server-pub=
			PresharedKey = psk-from-conf=
			AllowedIPs = 0.0.0.0/0
			Endpoint = vpn.example.org:51820
			PersistentKeepalive = 25
			CONF);

		$this->cleanup[] = $dir;
		return $dir;
	}
}
