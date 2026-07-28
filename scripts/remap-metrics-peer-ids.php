<?php

declare(strict_types=1);

/**
 * P6 cutover step: re-key historical metrics onto stable peer identities.
 *
 * Thin wrapper around `MetricsPeerRemapper` for hosts where running occ is
 * awkward. The supported entry point is the OCC command, which prints the same
 * numbers in a table:
 *
 *   docker exec cloud_app php occ nc_wireguard:remap-metrics            # dry run
 *   docker exec cloud_app php occ nc_wireguard:remap-metrics --apply
 *
 * This script:
 *
 *   docker exec cloud_app php \
 *     /var/www/html/custom_apps/nc_wireguard/scripts/remap-metrics-peer-ids.php [--apply]
 *
 * Dry run unless `--apply` is passed. Idempotent: only blank identity columns
 * are filled, so re-running after a partial failure is safe. Does not touch the
 * engine, and is safe to run while production is still on wg-easy.
 */

require '/var/www/html/lib/base.php';

use OCA\NcWireguard\Service\MetricsPeerRemapper;

$apply = in_array('--apply', array_slice($argv, 1), true);

$result = \OC::$server->get(MetricsPeerRemapper::class)->run(!$apply);

echo 'peers with a wg-easy id: ' . $result['mapped'] . "\n";
foreach ($result['tables'] as $table => $counts) {
	printf(
		"%-24s needing=%-8d written=%-8d orphaned=%d\n",
		$table,
		$counts['matched'],
		$counts['updated'],
		$counts['orphaned']
	);
}
if ($result['unmapped'] !== []) {
	echo 'no peer for wg-easy client ids: ' . implode(', ', $result['unmapped']) . "\n";
}
echo $result['dry_run']
	? "dry run - nothing written, re-run with --apply\n"
	: "remap-metrics OK\n";
