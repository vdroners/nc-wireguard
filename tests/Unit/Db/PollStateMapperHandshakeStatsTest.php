<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Db;

use OCA\NcWireguard\Db\PollStateMapper;
use PHPUnit\Framework\TestCase;

final class PollStateMapperHandshakeStatsTest extends TestCase
{
	public function testDisconnectedRowIsNotCountedAsNever(): void
	{
		$stats = PollStateMapper::aggregateHandshakeFlags([true, false, false, true]);

		self::assertSame(4, $stats['total']);
		self::assertSame(2, $stats['connected']);
		self::assertSame(2, $stats['not_connected']);
		self::assertNull($stats['never']);
		// Idle / outside FSM window must not be labelled "never handshaked".
		self::assertNotSame($stats['not_connected'], $stats['never']);
	}

	public function testEmptyTable(): void
	{
		$stats = PollStateMapper::aggregateHandshakeFlags([]);
		self::assertSame(0, $stats['total']);
		self::assertSame(0, $stats['connected']);
		self::assertSame(0, $stats['not_connected']);
		self::assertNull($stats['never']);
	}
}
