<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\OtlRedeemRateLimiter;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class OtlRedeemRateLimiterTest extends TestCase
{
	public function testAllowsUntilLimitThenBlocks(): void
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

		$limiter = new OtlRedeemRateLimiter($config);
		for ($i = 0; $i < OtlRedeemRateLimiter::LIMIT; $i++) {
			$result = $limiter->attempt('203.0.113.10');
			self::assertTrue($result['allowed'], "attempt $i should be allowed");
		}
		$blocked = $limiter->attempt('203.0.113.10');
		self::assertFalse($blocked['allowed']);
		self::assertGreaterThan(0, $blocked['retry_after']);

		// Different IP stays independent.
		$other = $limiter->attempt('198.51.100.7');
		self::assertTrue($other['allowed']);
	}
}
