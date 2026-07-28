<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Db;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Db\PeerSecret;
use OCA\NcWireguard\Db\PeerSecretMapper;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Db\ServerConfigMapper;
use OCA\NcWireguard\Service\SchemaRegistry;
use OCA\NcWireguard\Tests\Stubs\NullDbConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * QBMapper hydrates by column name, so every peer-store column needs a matching
 * entity property.
 */
final class PeerEntityTest extends TestCase
{
	/** @return array<string, array{0: class-string, 1: list<string>, 2: list<string>}> */
	public static function entityCases(): array
	{
		return [
			'Peer' => [Peer::class, SchemaRegistry::TABLES['nc_wg_peers'], ['id']],
			'PeerSecret' => [
				PeerSecret::class,
				SchemaRegistry::TABLES['nc_wg_peer_secrets'],
				[],
			],
			'ServerConfig' => [ServerConfig::class, SchemaRegistry::TABLES['nc_wg_server'], ['id']],
		];
	}

	/**
	 * @dataProvider entityCases
	 * @param class-string $class
	 * @param list<string> $columns
	 * @param list<string> $inherited columns handled by the Entity base class
	 */
	public function testPropertyExistsForEveryColumn(string $class, array $columns, array $inherited): void
	{
		$reflection = new ReflectionClass($class);

		foreach ($columns as $column) {
			if (in_array($column, $inherited, true)) {
				continue;
			}
			$camel = self::toCamelCase($column);
			$this->assertTrue(
				$reflection->hasProperty($camel) || $reflection->hasMethod('set' . ucfirst($camel)),
				"{$class} missing property '{$camel}' for column '{$column}'"
			);
		}
	}

	public function testMappersTargetThePeerStoreTables(): void
	{
		$db = new NullDbConnection();

		self::assertSame('nc_wg_peers', (new PeerMapper($db))->getTableName());
		self::assertSame('nc_wg_peer_secrets', (new PeerSecretMapper($db))->getTableName());
		self::assertSame('nc_wg_server', (new ServerConfigMapper($db))->getTableName());
	}

	public function testPeerSecretUsesPeerIdAsItsIdentity(): void
	{
		$secret = new PeerSecret();
		$secret->setId(42);

		self::assertSame(42, $secret->getPeerId());
		self::assertSame(42, $secret->getId());
	}

	private static function toCamelCase(string $snake): string
	{
		$parts = explode('_', $snake);
		$first = array_shift($parts);
		return $first . implode('', array_map('ucfirst', $parts));
	}
}
