<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

$db = \OC::$server->get(\OCP\IDBConnection::class);
$tables = [
	'nc_wg_bandwidth_log',
	'nc_wg_connection_log',
	'nc_wg_geoip_cache',
	'nc_wg_system_metrics',
	'nc_wg_poll_state',
	'nc_wg_metrics_heartbeat',
];
foreach ($tables as $table) {
	$qb = $db->getQueryBuilder();
	$qb->select($qb->createFunction('COUNT(*) AS c'))
		->from($table);
	$row = $qb->executeQuery()->fetchAssociative();
	echo $table . '=' . ($row['c'] ?? 0) . "\n";
}

$qb = $db->getQueryBuilder();
$qb->select('last_poll_at', 'success', 'wg_easy_ok', 'error_message')
	->from('nc_wg_metrics_heartbeat')
	->setMaxResults(1);
$hb = $qb->executeQuery()->fetchAssociative();
echo 'heartbeat=' . json_encode($hb) . "\n";

$qb = $db->getQueryBuilder();
$qb->select('event', 'endpoint')
	->from('nc_wg_connection_log')
	->orderBy('id', 'DESC')
	->setMaxResults(3);
$conn = $qb->executeQuery()->fetchAllAssociative();
echo 'recent_connections=' . json_encode($conn) . "\n";

$qb = $db->getQueryBuilder();
$qb->select('ip', 'country_code', 'city')
	->from('nc_wg_geoip_cache')
	->setMaxResults(3);
$geo = $qb->executeQuery()->fetchAllAssociative();
echo 'geoip=' . json_encode($geo) . "\n";
