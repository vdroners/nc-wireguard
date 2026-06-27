<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\PollState;
use OCA\NcWireguard\Service\ConnectionStateMachine;
use PHPUnit\Framework\TestCase;

final class ConnectionStateMachineTest extends TestCase
{
	private ConnectionStateMachine $fsm;

	protected function setUp(): void
	{
		$this->fsm = new ConnectionStateMachine();
	}

	public function testConnectedWithinTimeout(): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$hs = $now->modify('-60 seconds')->format('Y-m-d\TH:i:s\Z');
		$client = ['latestHandshakeAt' => $hs];
		self::assertTrue($this->fsm->isConnected($client, $now));
	}

	public function testDisconnectedAfterTimeout(): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$hs = $now->modify('-200 seconds')->format('Y-m-d\TH:i:s\Z');
		$client = ['latestHandshakeAt' => $hs];
		self::assertFalse($this->fsm->isConnected($client, $now));
	}

	public function testConnectEvent(): void
	{
		$events = $this->fsm->transitionEvents(
			['endpoint' => '203.0.113.5:51820'],
			null,
			true
		);
		self::assertCount(1, $events);
		self::assertSame('connected', $events[0]['event']);
	}

	public function testDisconnectEventUsesPreviousEndpoint(): void
	{
		$prev = new PollState();
		$prev->setConnected(true);
		$prev->setEndpoint('203.0.113.5:51820');
		$events = $this->fsm->transitionEvents(['endpoint' => null], $prev, false);
		self::assertCount(1, $events);
		self::assertSame('disconnected', $events[0]['event']);
		self::assertSame('203.0.113.5:51820', $events[0]['endpoint']);
	}

	public function testNoEventWhenStateUnchanged(): void
	{
		$prev = new PollState();
		$prev->setConnected(true);
		self::assertSame([], $this->fsm->transitionEvents([], $prev, true));
		self::assertSame([], $this->fsm->transitionEvents([], null, false));
	}
}
