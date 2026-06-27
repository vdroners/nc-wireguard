<?php

declare(strict_types=1);

/**
 * Service-layer smoke for native backend (no HTTP session required).
 * Run inside cloud_app: php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php
 */

require '/var/www/html/lib/base.php';

use OCA\NcWireguard\Service\NativeDashboardService;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyClient;

$dashboard = \OC::$server->get(NativeDashboardService::class);
$healthSvc = \OC::$server->get(NativeHealthService::class);
$wgEasy = \OC::$server->get(WgEasyClient::class);
$config = \OC::$server->get(\OCP\IConfig::class);
$ver = $config->getAppValue('nc_wireguard', 'installed_version', '');

$summary = $dashboard->buildSummary();
if (isset($summary['error'])) {
	fwrite(STDERR, 'summary error: ' . $summary['error'] . "\n");
	exit(1);
}
if (!isset($summary['clients']) || !is_array($summary['clients'])) {
	fwrite(STDERR, 'summary missing clients array' . "\n");
	exit(1);
}

$bandwidth = $dashboard->fetchBandwidth(24, null);
$connections = $dashboard->fetchConnections(7, null);
$geoip = $dashboard->fetchGeoip();
$system = $dashboard->fetchSystem(24);

if (!is_array($bandwidth) || !is_array($connections) || !is_array($geoip) || !is_array($system)) {
	fwrite(STDERR, 'dashboard fetch returned non-array' . "\n");
	exit(1);
}

$health = $healthSvc->getHealth($ver);
if (!$health['wg_easy']) {
	fwrite(STDERR, 'wg_easy not ok' . "\n");
	exit(1);
}

$clientId = null;
foreach ($summary['clients'] as $c) {
	if (isset($c['id']) && (int) $c['id'] > 0) {
		$clientId = (int) $c['id'];
		break;
	}
}
if ($clientId !== null) {
	$filtered = $dashboard->fetchBandwidth(24, $clientId);
	if (!is_array($filtered)) {
		fwrite(STDERR, 'bandwidth client filter failed' . "\n");
		exit(1);
	}
}

if ($clientId !== null) {
	$configResult = $wgEasy->getClientConfiguration($clientId);
	if (!($configResult['ok'] ?? false) || ($configResult['body'] ?? '') === '') {
		fwrite(STDERR, 'peer configuration failed for client ' . $clientId . "\n");
		exit(1);
	}
}

echo 'smoke-native OK clients=' . count($summary['clients'])
	. ' bandwidth=' . count($bandwidth)
	. ' connections=' . count($connections)
	. ' geoip=' . count($geoip)
	. ' system=' . count($system) . "\n";
