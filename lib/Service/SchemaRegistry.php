<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Canonical table + column inventory for occ nc_wireguard:schema-check and entity tests.
 */
final class SchemaRegistry
{
	/** @var array<string, list<string>> */
	public const TABLES = [
		'nc_wg_bandwidth_log' => [
			'id', 'ts', 'client_id', 'name', 'transfer_rx', 'transfer_tx',
		],
		'nc_wg_connection_log' => [
			'id', 'ts', 'client_id', 'name', 'event', 'endpoint',
		],
		'nc_wg_geoip_cache' => [
			'ip', 'country', 'country_code', 'city', 'region', 'lat', 'lon', 'isp', 'queried_at',
		],
		'nc_wg_system_metrics' => [
			'id', 'ts', 'cpu_percent', 'mem_percent', 'disk_percent', 'net_rx_bytes', 'net_tx_bytes',
		],
		'nc_wg_poll_state' => [
			'client_id', 'connected', 'endpoint', 'updated_at',
		],
		'nc_wg_metrics_heartbeat' => [
			'id', 'last_poll_at', 'success', 'wg_easy_ok', 'error_message', 'updated_at',
		],
	];
}
