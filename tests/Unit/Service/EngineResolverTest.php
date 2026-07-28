<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTime;
use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\EngineResolver;
use OCA\NcWireguard\Service\SecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Tests\Unit\Doubles\InMemoryPeerMapper;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * This class decides which engine owns the production tunnel on every request,
 * so each precondition is asserted separately — a resolver that flips one step
 * too early points the field fleet at an empty peer set.
 */
final class EngineResolverTest extends TestCase
{
	public function testDefaultsToWgEasy(): void
	{
		$resolver = $this->resolver([], 0);

		self::assertFalse($resolver->useNative());
		self::assertSame(AppSettings::ENGINE_WG_EASY, $resolver->activeEngine());
		self::assertSame('engine is set to wgeasy', $resolver->blockReason());
	}

	public function testNativeIsBlockedWhileImportIsIncomplete(): void
	{
		$resolver = $this->resolver(['engine' => 'native'], 3);

		self::assertFalse($resolver->useNative());
		self::assertStringContainsString('import', (string) $resolver->blockReason());
	}

	public function testNativeIsBlockedOnAnEmptyPeerStore(): void
	{
		$resolver = $this->resolver(['engine' => 'native', 'import_complete' => '1'], 0);

		self::assertFalse($resolver->useNative());
		self::assertSame('peer store is empty', $resolver->blockReason());
	}

	public function testNativeActivatesOnlyWithEveryPrecondition(): void
	{
		$resolver = $this->resolver(['engine' => 'native', 'import_complete' => '1'], 2);

		self::assertTrue($resolver->useNative());
		self::assertNull($resolver->blockReason());
		self::assertSame(AppSettings::ENGINE_NATIVE, $resolver->activeEngine());
	}

	public function testStatusFlagsAnOperatorsIntentThatIsNotInForce(): void
	{
		$status = $this->resolver(['engine' => 'native'], 0)->status();

		self::assertSame('native', $status['configured']);
		self::assertSame('wgeasy', $status['active']);
		self::assertTrue($status['blocked']);
	}

	public function testStatusIsNotBlockedWhenWgEasyIsWhatWasAskedFor(): void
	{
		$status = $this->resolver([], 0)->status();

		self::assertSame('wgeasy', $status['configured']);
		self::assertFalse($status['blocked']);
	}

	/**
	 * @param array<string, string> $config
	 */
	private function resolver(array $config, int $peerCount): EngineResolver
	{
		$arrayConfig = new ArrayConfig($config);
		$settings = new AppSettings(
			$arrayConfig,
			new SecretCrypto(
				$arrayConfig,
				$this->createMock(ICrypto::class),
				$this->createMock(LoggerInterface::class)
			),
			new DockerUrlResolver($this->createMock(LoggerInterface::class))
		);

		$peers = new InMemoryPeerMapper();
		for ($i = 1; $i <= $peerCount; $i++) {
			$peer = new Peer();
			$peer->setUuid('uuid-' . $i);
			$peer->setPublicKey('key-' . $i);
			$peer->setName('peer-' . $i);
			$peer->setEnabled(true);
			$peer->setIpv4('10.8.0.' . ($i + 1) . '/32');
			$peer->setHasAmnezia(false);
			$peer->setCreatedAt(new DateTime());
			$peer->setUpdatedAt(new DateTime());
			$peers->insert($peer);
		}

		return new EngineResolver($settings, $peers, $this->createMock(LoggerInterface::class));
	}
}
