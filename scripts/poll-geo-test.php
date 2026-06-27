<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

/** @var \OCA\NcWireguard\Service\GeoIpService $geo */
$geo = \OC::$server->get(\OCA\NcWireguard\Service\GeoIpService::class);
$geo->resolve('8.8.8.8');

$db = \OC::$server->get(\OCP\IDBConnection::class);
$qb = $db->getQueryBuilder();
$qb->select('ip', 'country_code', 'city')
	->from('nc_wg_geoip_cache')
	->where($qb->expr()->eq('ip', $qb->createNamedParameter('8.8.8.8')));
$row = $qb->executeQuery()->fetchAssociative();
echo 'geoip_lookup=' . json_encode($row) . "\n";

// Simulate connect event path: reset poll_state then verify FSM fires on next poll when peer connects.
$qb = $db->getQueryBuilder();
$qb->update('nc_wg_poll_state')
	->set('connected', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
	->where($qb->expr()->eq('client_id', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
$qb->executeStatement();
echo "poll_state_reset_client_1=ok\n";
