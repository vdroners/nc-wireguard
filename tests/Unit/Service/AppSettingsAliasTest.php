<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\SecretCrypto;
use OCA\NcWireguard\Tests\Unit\Doubles\ArrayConfig;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P6 renames `wg_easy_*` to `engine_*`. An operator's existing
 * `occ config:app:set` and any scripted deploy have to keep working across the
 * upgrade, so both names are read — and the legacy one must still win nothing
 * when the new one is set.
 */
final class AppSettingsAliasTest extends TestCase
{
	public function testLegacyKeysAreStillRead(): void
	{
		$settings = $this->settings([
			'wg_easy_api_url' => 'http://wg-easy:51821',
			'wg_easy_username' => 'svc',
			'wg_easy_admin_url' => 'http://127.0.0.1:51821',
		]);

		self::assertSame('http://wg-easy:51821', $settings->getWgEasyApiUrl());
		self::assertSame('svc', $settings->getWgEasyUsername());
		self::assertSame('http://127.0.0.1:51821', $settings->getWgEasyAdminUrl());
	}

	public function testNewNamesWinOverLegacyOnes(): void
	{
		$settings = $this->settings([
			'wg_easy_api_url' => 'http://wg-easy:51821',
			'engine_api_url' => 'http://wg_sync:51821',
		]);

		self::assertSame('http://wg_sync:51821', $settings->getWgEasyApiUrl());
	}

	public function testEveryAliasResolvesBothWays(): void
	{
		// The boolean setting needs values its getter actually distinguishes;
		// everything else is free-form text.
		$pairs = ['legacy' => 'legacy-value', 'new' => 'new-value'];
		foreach (AppSettings::SETTING_ALIASES as $new => $legacy) {
			$values = $new === 'hide_engine_admin_link' ? ['legacy' => '1', 'new' => '0'] : $pairs;

			$settings = $this->settings([$legacy => $values['legacy']]);
			self::assertSame(
				$values['legacy'],
				$this->read($settings, $new),
				$new . ' did not fall back to ' . $legacy
			);

			$settings = $this->settings([$new => $values['new'], $legacy => $values['legacy']]);
			self::assertSame($values['new'], $this->read($settings, $new), $new . ' did not win');
		}
	}

	public function testBooleanAliasFallsBackToTheLegacyKey(): void
	{
		// Hidden by default: Nextcloud is the peer controller and the wg-easy
		// admin UI is expected to be unpublished.
		self::assertTrue($this->settings([])->isWgEasyAdminLinkHidden());
		self::assertFalse($this->settings(['hide_wg_easy_admin_link' => '0'])->isWgEasyAdminLinkHidden());
		self::assertTrue($this->settings(['hide_wg_easy_admin_link' => 'true'])->isWgEasyAdminLinkHidden());
		self::assertFalse($this->settings(['hide_engine_admin_link' => '0'])->isWgEasyAdminLinkHidden());
	}

	public function testPeerWritesAreNotFrozenByDefault(): void
	{
		self::assertFalse($this->settings([])->arePeerWritesFrozen());
	}

	public function testPeerWriteFreezeIsOptIn(): void
	{
		self::assertTrue($this->settings(['peer_writes_frozen' => '1'])->arePeerWritesFrozen());
		// Anything other than an explicit "1" leaves writes open, so a stray
		// value cannot silently take peer CRUD down.
		self::assertFalse($this->settings(['peer_writes_frozen' => 'true'])->arePeerWritesFrozen());
	}

	public function testImportIsIncompleteUntilSaidOtherwise(): void
	{
		$settings = $this->settings([]);
		self::assertFalse($settings->isImportComplete());

		$settings->setImportComplete(true);
		self::assertTrue($settings->isImportComplete());
	}

	/**
	 * Reading through the public accessor that owns each aliased name, so the
	 * test covers the real call path rather than the private helper.
	 */
	private function read(AppSettings $settings, string $newKey): string
	{
		return match ($newKey) {
			'engine_api_url' => $settings->getWgEasyApiUrl(),
			'engine_username' => $settings->getWgEasyUsername(),
			'engine_password' => $settings->getWgEasyPassword(),
			'engine_admin_url' => $settings->getWgEasyAdminUrl(),
			'hide_engine_admin_link' => $settings->isWgEasyAdminLinkHidden() ? '1' : '0',
			default => self::fail('no accessor mapped for ' . $newKey),
		};
	}

	/**
	 * @param array<string, string> $values
	 */
	private function settings(array $values): AppSettings
	{
		$config = new ArrayConfig($values);
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
