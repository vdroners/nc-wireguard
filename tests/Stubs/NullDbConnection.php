<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Stubs;

use OCP\IDBConnection;

/** Minimal IDBConnection stub for unit tests that do not touch the DB. */
final class NullDbConnection implements IDBConnection
{
	public function createSchema()
	{
		return new \stdClass();
	}

	public function getQueryBuilder()
	{
		throw new \RuntimeException('NullDbConnection: no query builder');
	}
}
