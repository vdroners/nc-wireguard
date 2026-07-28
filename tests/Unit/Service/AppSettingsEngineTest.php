<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\SecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The engine switch decides who owns the production tunnel, so its default and
 * its rejection of unknown values both matter more than the usual setting.
 */
final class AppSettingsEngineTest extends TestCase
{
	public function testEngineDefaultsToWgEasy(): void
	{
		self::assertSame(AppSettings::ENGINE_WG_EASY, $this->settings()->getEngine());
	}

	public function testOtlSourceDefaultsToWgEasy(): void
	{
		self::assertSame(AppSettings::OTL_SOURCE_WG_EASY, $this->settings()->getOtlSource());
	}

	public function testEngineRoundTrips(): void
	{
		$config = new ArrayConfig();
		$settings = $this->settings($config);

		$settings->setEngine(AppSettings::ENGINE_NATIVE);

		self::assertSame(AppSettings::ENGINE_NATIVE, $settings->getEngine());
		self::assertSame('native', $config->all()['engine']);
	}

	public function testOtlSourceRoundTrips(): void
	{
		$settings = $this->settings();

		$settings->setOtlSource(AppSettings::OTL_SOURCE_NC);

		self::assertSame(AppSettings::OTL_SOURCE_NC, $settings->getOtlSource());
	}

	public function testUnknownStoredValuesFallBackToWgEasy(): void
	{
		$settings = $this->settings(new ArrayConfig([
			'engine' => 'wg-sync-lab',
			'otl_source' => 'somewhere',
		]));

		self::assertSame(AppSettings::ENGINE_WG_EASY, $settings->getEngine());
		self::assertSame(AppSettings::OTL_SOURCE_WG_EASY, $settings->getOtlSource());
	}

	public function testWritingAnUnknownEngineIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->settings()->setEngine('tailscale');
	}

	public function testWritingAnUnknownOtlSourceIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->settings()->setOtlSource('carrier-pigeon');
	}

	private function settings(?ArrayConfig $config = null): AppSettings
	{
		$config ??= new ArrayConfig();
		return new AppSettings(
			$config,
			new SecretCrypto(
				$config,
				$this->createMock(ICrypto::class),
				$this->createMock(LoggerInterface::class)
			),
			new DockerUrlResolver($this->createMock(LoggerInterface::class))
		);
	}
}
