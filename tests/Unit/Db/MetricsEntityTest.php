<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Db;

use OCA\NcWireguard\Service\SchemaRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * QBMapper requires every DB column to map to an entity property.
 */
class MetricsEntityTest extends TestCase
{
	/** @return array<string, array{0: class-string, 1: list<string>}> */
	public static function entityCases(): array
	{
		return [
			'BandwidthLog' => [\OCA\NcWireguard\Db\BandwidthLog::class, SchemaRegistry::TABLES['nc_wg_bandwidth_log']],
			'ConnectionLog' => [\OCA\NcWireguard\Db\ConnectionLog::class, SchemaRegistry::TABLES['nc_wg_connection_log']],
			'GeoIpCache' => [\OCA\NcWireguard\Db\GeoIpCache::class, SchemaRegistry::TABLES['nc_wg_geoip_cache']],
			'SystemMetrics' => [\OCA\NcWireguard\Db\SystemMetrics::class, SchemaRegistry::TABLES['nc_wg_system_metrics']],
			'PollState' => [\OCA\NcWireguard\Db\PollState::class, SchemaRegistry::TABLES['nc_wg_poll_state']],
			'MetricsHeartbeat' => [\OCA\NcWireguard\Db\MetricsHeartbeat::class, SchemaRegistry::TABLES['nc_wg_metrics_heartbeat']],
		];
	}

	/**
	 * @dataProvider entityCases
	 * @param class-string $class
	 * @param list<string> $columns
	 */
	public function testSetterExistsForColumn(string $class, array $columns): void
	{
		$reflection = new ReflectionClass($class);

		foreach ($columns as $column) {
			if ($column === 'id') {
				continue;
			}
			$camel = $this->toCamelCase($column);
			$setter = 'set' . ucfirst($camel);
			$hasProperty = $reflection->hasProperty($camel);
			$hasMethod = $reflection->hasMethod($setter);
			$this->assertTrue(
				$hasProperty || $hasMethod,
				"{$class} missing property '{$camel}' for column '{$column}'"
			);
		}
	}

	private function toCamelCase(string $snake): string
	{
		$parts = explode('_', $snake);
		$first = array_shift($parts);
		return $first . implode('', array_map('ucfirst', $parts));
	}
}
