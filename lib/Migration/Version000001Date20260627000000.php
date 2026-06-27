<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Native metrics storage (v1.2.0-alpha): six tables mirroring wg-dashboard SQLite
 * plus poll_state FSM persistence and metrics heartbeat for the native poller.
 */
class Version000001Date20260627000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createBandwidthLog($schema);
		$this->createConnectionLog($schema);
		$this->createGeoIpCache($schema);
		$this->createSystemMetrics($schema);
		$this->createPollState($schema);
		$this->createMetricsHeartbeat($schema);

		return $schema;
	}

	private function createBandwidthLog(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_bandwidth_log')) {
			return;
		}
		$table = $schema->createTable('nc_wg_bandwidth_log');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('ts', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('client_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('transfer_rx', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('transfer_tx', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ts'], 'nc_wg_bw_ts_idx');
		$table->addIndex(['client_id'], 'nc_wg_bw_client_idx');
	}

	private function createConnectionLog(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_connection_log')) {
			return;
		}
		$table = $schema->createTable('nc_wg_connection_log');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('ts', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('client_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('endpoint', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ts'], 'nc_wg_conn_ts_idx');
		$table->addIndex(['client_id'], 'nc_wg_conn_client_idx');
	}

	private function createGeoIpCache(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_geoip_cache')) {
			return;
		}
		$table = $schema->createTable('nc_wg_geoip_cache');
		$table->addColumn('ip', Types::STRING, ['notnull' => true, 'length' => 45]);
		$table->addColumn('country', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('country_code', Types::STRING, ['notnull' => false, 'length' => 8]);
		$table->addColumn('city', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('region', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('lat', Types::FLOAT, ['notnull' => false]);
		$table->addColumn('lon', Types::FLOAT, ['notnull' => false]);
		$table->addColumn('isp', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('queried_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['ip']);
	}

	private function createSystemMetrics(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_system_metrics')) {
			return;
		}
		$table = $schema->createTable('nc_wg_system_metrics');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('ts', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('cpu_percent', Types::FLOAT, ['notnull' => true]);
		$table->addColumn('mem_percent', Types::FLOAT, ['notnull' => true]);
		$table->addColumn('disk_percent', Types::FLOAT, ['notnull' => true]);
		$table->addColumn('net_rx_bytes', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('net_tx_bytes', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ts'], 'nc_wg_sys_ts_idx');
	}

	private function createPollState(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_poll_state')) {
			return;
		}
		$table = $schema->createTable('nc_wg_poll_state');
		$table->addColumn('client_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('connected', Types::BOOLEAN, ['notnull' => true, 'default' => 0]);
		$table->addColumn('endpoint', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['client_id']);
	}

	private function createMetricsHeartbeat(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_metrics_heartbeat')) {
			return;
		}
		$table = $schema->createTable('nc_wg_metrics_heartbeat');
		$table->addColumn('id', Types::INTEGER, ['notnull' => true, 'default' => 1]);
		$table->addColumn('last_poll_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('success', Types::BOOLEAN, ['notnull' => true, 'default' => 0]);
		$table->addColumn('wg_easy_ok', Types::BOOLEAN, ['notnull' => true, 'default' => 0]);
		$table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
	}
}
