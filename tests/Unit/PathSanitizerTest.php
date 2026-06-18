<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit;

use OCA\NcWireguard\Service\PathSanitizer;
use PHPUnit\Framework\TestCase;

class PathSanitizerTest extends TestCase
{
	public function testBlocksTraversal(): void
	{
		$this->assertTrue(PathSanitizer::hasTraversalAttempt('../summary'));
		$this->assertTrue(PathSanitizer::hasTraversalAttempt('foo/../../bar'));
	}

	public function testNormalizesSafePath(): void
	{
		$this->assertSame('summary', PathSanitizer::normalize('summary'));
		$this->assertSame('bandwidth', PathSanitizer::normalize('./bandwidth'));
	}
}
