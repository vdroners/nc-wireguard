<?php

declare(strict_types=1);

/**
 * Verify native /api/status health semantics (poll age + wg-easy).
 * Run inside cloud_app after poll-metrics.
 */

require '/var/www/html/lib/base.php';

use OCA\NcWireguard\Service\NativeHealthService;

$maxAge = (int) ($argv[1] ?? 60);
$healthSvc = \OC::$server->get(NativeHealthService::class);
$config = \OC::$server->get(\OCP\IConfig::class);
$ver = $config->getAppValue('nc_wireguard', 'installed_version', '');

$health = $healthSvc->getHealth($ver);
$last = $health['last_poll_at'] ?? null;
if ($last === null || $last === '') {
	fwrite(STDERR, 'last_poll_at missing' . "\n");
	exit(1);
}

$age = time() - (new \DateTimeImmutable($last))->getTimestamp();
if ($age > $maxAge) {
	fwrite(STDERR, "last poll age {$age}s exceeds {$maxAge}s\n");
	exit(1);
}

if (!$health['wg_easy']) {
	fwrite(STDERR, 'wg_easy not ok' . "\n");
	exit(1);
}

if (($health['status'] ?? '') !== 'ok') {
	fwrite(STDERR, 'health status=' . ($health['status'] ?? 'null') . "\n");
	exit(1);
}

echo "verify-status-native OK age={$age}s wg_easy=1 poller=" . ($health['poller'] ? '1' : '0') . "\n";
