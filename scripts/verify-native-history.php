<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

$connection = \OC::$server->get(\OCP\IDBConnection::class);
$qb = $connection->getQueryBuilder();
$qb->selectAlias($qb->func()->count('*'), 'cnt')->from('nc_wg_bandwidth_log');
echo 'bandwidth rows: ' . $qb->executeQuery()->fetchOne() . "\n";

$qb = $connection->getQueryBuilder();
$qb->select('ts')->from('nc_wg_bandwidth_log')->orderBy('ts', 'ASC')->setMaxResults(1);
echo 'oldest ts: ' . $qb->executeQuery()->fetchOne() . "\n";

$svc = \OC::$server->get(\OCA\NcWireguard\Service\NativeDashboardService::class);
$bw = $svc->fetchBandwidth(720, null);
echo 'native API bandwidth points (720h): ' . count($bw) . "\n";
$sys = $svc->fetchSystem(720);
echo 'native API system points (720h): ' . count($sys) . "\n";
