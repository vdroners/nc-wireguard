<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Controller;

use OCA\NcWireguard\Controller\PeerWriteController;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NcOtlService;
use OCA\NcWireguard\Service\OtlRedeemRateLimiter;
use OCA\NcWireguard\Service\OtlRedeemTracker;
use OCA\NcWireguard\Service\PeerFieldValidator;
use OCA\NcWireguard\Tests\Unit\FakeWireGuardEngine;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The write surface is admin-only and honours the dashboard kill switch.
 * Both are security-relevant, so they are asserted directly on gate().
 */
final class PeerWriteControllerGateTest extends TestCase
{
	private function makeController(
		?string $uid,
		bool $isAdmin,
		bool $dashboardEnabled,
		bool $writesFrozen = false,
	): PeerWriteController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$settings = $this->createMock(AppSettings::class);
		$settings->method('isDashboardEnabled')->willReturn($dashboardEnabled);
		$settings->method('arePeerWritesFrozen')->willReturn($writesFrozen);

		return new PeerWriteController(
			$this->createMock(IRequest::class),
			$settings,
			new FakeWireGuardEngine(),
			$this->createMock(PeerFieldValidator::class),
			$this->createMock(NcOtlService::class),
			$this->createMock(OtlRedeemRateLimiter::class),
			$this->createMock(OtlRedeemTracker::class),
			$groups,
			$session,
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function invokeGate(PeerWriteController $controller, string $gate = 'gate'): mixed
	{
		$method = (new ReflectionClass($controller))->getMethod($gate);
		$method->setAccessible(true);
		return $method->invoke($controller);
	}

	public function testAnonymousSessionIsForbidden(): void
	{
		$resp = $this->invokeGate($this->makeController(null, false, true));
		self::assertNotNull($resp);
		self::assertSame(403, $resp->getStatus());
		self::assertSame('no_permission', $resp->getData()['reason']);
	}

	public function testNonAdminUserIsForbidden(): void
	{
		$resp = $this->invokeGate($this->makeController('alice', false, true));
		self::assertNotNull($resp);
		self::assertSame(403, $resp->getStatus());
	}

	public function testAdminIsBlockedWhileDashboardDisabled(): void
	{
		$resp = $this->invokeGate($this->makeController('admin', true, false));
		self::assertNotNull($resp);
		self::assertSame(503, $resp->getStatus());
		self::assertSame('disabled', $resp->getData()['reason']);
	}

	public function testAdminPassesWhenDashboardEnabled(): void
	{
		self::assertNull($this->invokeGate($this->makeController('admin', true, true)));
	}

	public function testCutoverFreezeBlocksWrites(): void
	{
		$controller = $this->makeController('admin', true, true, true);

		$resp = $this->invokeGate($controller, 'writeGate');

		self::assertNotNull($resp);
		self::assertSame(503, $resp->getStatus());
		self::assertSame('writes_frozen', $resp->getData()['reason']);
	}

	public function testCutoverFreezeLeavesReadsAlone(): void
	{
		// Config downloads and OTL redeems run on the plain gate: a field user
		// redeeming a link they already hold does not put the peer store and
		// the engine out of step, which is the only thing the freeze protects.
		$controller = $this->makeController('admin', true, true, true);

		self::assertNull($this->invokeGate($controller));
	}

	public function testWritesPassWhenNotFrozen(): void
	{
		$controller = $this->makeController('admin', true, true);

		self::assertNull($this->invokeGate($controller, 'writeGate'));
	}
}
