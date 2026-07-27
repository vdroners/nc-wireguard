<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\OtlRedeemTracker;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class OtlRedeemTrackerTest extends TestCase
{
	public function testMarksAndDetectsRedeemedToken(): void
	{
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				self::assertSame(Application::APP_ID, $app);
				return $store[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			}
		);

		$tracker = new OtlRedeemTracker($config);
		self::assertFalse($tracker->wasRedeemed('tok12345'));
		$tracker->markRedeemed('tok12345');
		self::assertTrue($tracker->wasRedeemed('tok12345'));
		self::assertFalse($tracker->wasRedeemed('other'));
	}
}
