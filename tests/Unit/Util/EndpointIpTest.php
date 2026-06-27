<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Util;

use OCA\NcWireguard\Util\EndpointIp;
use PHPUnit\Framework\TestCase;

final class EndpointIpTest extends TestCase
{
	public function testIpv4HostPort(): void
	{
		self::assertSame('203.0.113.5', EndpointIp::parse('203.0.113.5:51820'));
	}

	public function testIpv6Bracketed(): void
	{
		self::assertSame('2001:db8::1', EndpointIp::parse('[2001:db8::1]:51820'));
	}

	public function testBareIpv6(): void
	{
		self::assertSame('2001:db8::2', EndpointIp::parse('2001:db8::2:51820'));
	}

	public function testNullAndEmpty(): void
	{
		self::assertNull(EndpointIp::parse(null));
		self::assertNull(EndpointIp::parse(''));
		self::assertNull(EndpointIp::parse('   '));
	}
}
