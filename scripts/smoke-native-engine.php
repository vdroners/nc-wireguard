<?php

declare(strict_types=1);

/**
 * Lab smoke for the wg-sync sidecar + NativeEngine (P5).
 *
 * Run inside cloud_app:
 *   docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native-engine.php
 *
 * Exits 0 and SKIPs when wg-sync is not configured or not reachable, so this is
 * safe to wire into a gate on hosts that have no lab stack.
 *
 * Read-only against production: it instantiates NativeEngine directly rather
 * than going through the DI alias, so the engine setting is never consulted and
 * wg-easy is never touched. The only write is a `POST /apply` to wg-sync, which
 * the lab compose points at `wg-lab0` / 51830.
 */

require '/var/www/html/lib/base.php';

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\EngineResolver;
use OCA\NcWireguard\Service\NativeEngine;
use OCA\NcWireguard\Service\ServerKeyStore;
use OCA\NcWireguard\Service\WgSyncClient;

/** @param array<string, mixed> $extra */
function line(string $status, string $message, array $extra = []): void
{
	$suffix = $extra === [] ? '' : ' ' . json_encode($extra);
	echo str_pad($status, 5) . ' ' . $message . $suffix . "\n";
}

function skip(string $why): never
{
	line('SKIP', $why);
	echo "smoke-native-engine SKIPPED\n";
	exit(0);
}

function fail(string $why): never
{
	line('FAIL', $why);
	echo "smoke-native-engine FAILED\n";
	exit(1);
}

$settings = \OC::$server->get(AppSettings::class);
$wgSync = \OC::$server->get(WgSyncClient::class);
$engine = \OC::$server->get(NativeEngine::class);
$resolver = \OC::$server->get(EngineResolver::class);
$serverKeys = \OC::$server->get(ServerKeyStore::class);

// --- preconditions --------------------------------------------------------

if (!$wgSync->isConfigured()) {
	skip('wg_sync_url / wg_sync_token not set — start services/wg-sync/docker-compose.lab.yml first');
}

$health = $wgSync->health();
if (!$health['ok']) {
	skip('wg-sync unreachable: ' . $health['error']);
}
$sidecar = is_array($health['json']) ? $health['json'] : [];
line('OK', 'wg-sync health', [
	'interface' => $sidecar['interface'] ?? '?',
	'up' => $sidecar['up'] ?? false,
	'peers' => $sidecar['peer_count'] ?? 0,
]);

// Guard: this smoke must never run against the production tunnel.
if (($sidecar['interface'] ?? '') === 'wg0' || ($sidecar['allow_prod'] ?? false) === true) {
	fail('wg-sync is pointed at the production interface — refusing to apply from a smoke test');
}

if (!$serverKeys->isConfigured()) {
	skip('no interface key stored — run: occ nc_wireguard:set-server-key --generate (lab)');
}

// --- engine surface -------------------------------------------------------

$peers = $engine->listPeers();
if (!is_array($peers)) {
	fail('listPeers() returned null');
}
line('OK', 'listPeers', ['count' => count($peers)]);

$server = $engine->getServerInfo();
if (($server['engine'] ?? '') !== 'native') {
	fail('getServerInfo() did not identify as the native engine');
}
line('OK', 'getServerInfo', [
	'cidr' => $server['ipv4Cidr'] ?? null,
	'interface' => $server['interfaceName'] ?? null,
]);

$refusal = $engine->update(0, ['jc' => 4]);
if (($refusal['code'] ?? '') !== NativeEngine::ERR_AMNEZIA) {
	fail('Amnezia fields were not refused (got ' . json_encode($refusal) . ')');
}
line('OK', 'Amnezia obfuscation refused before any write');

$refusal = $engine->update(0, ['ipv6Address' => 'fd00::2']);
if (($refusal['code'] ?? '') !== NativeEngine::ERR_IPV6) {
	fail('IPv6 address was not refused (got ' . json_encode($refusal) . ')');
}
line('OK', 'IPv6 address refused (IPv4-only policy)');

// --- apply ----------------------------------------------------------------

$sync = $engine->syncToSidecar();
if (!$sync['ok']) {
	fail('syncToSidecar failed: ' . ($sync['error'] ?? 'unknown'));
}
line('OK', 'peer set applied to the lab interface');

$stats = $engine->getRuntimeStats();
line('OK', 'getRuntimeStats', ['keys' => count($stats)]);

foreach ($peers as $peer) {
	$id = (int) ($peer['id'] ?? 0);
	if ($id < 1) {
		continue;
	}
	$conf = $engine->getConfiguration($id);
	if (!$conf['ok']) {
		// A peer imported without key material cannot yield a config; that is
		// a known import gap, not a smoke failure.
		line('WARN', 'no config for peer ' . $id . ': ' . $conf['error']);
		continue;
	}
	$body = (string) $conf['body'];
	if (!str_contains($body, '[Interface]') || !str_contains($body, '[Peer]')) {
		fail('config for peer ' . $id . ' is not a WireGuard conf');
	}
	if (str_contains($body, '::/0')) {
		fail('config for peer ' . $id . ' carries an IPv6 default route');
	}
	line('OK', 'built config for peer ' . $id, ['bytes' => strlen($body)]);
	break;
}

// --- production is untouched ---------------------------------------------

$status = $resolver->status();
line('OK', 'engine resolver', $status);
if ($settings->getEngine() === AppSettings::ENGINE_WG_EASY && $status['active'] !== AppSettings::ENGINE_WG_EASY) {
	fail('resolver activated the native engine while engine=wgeasy');
}

echo "smoke-native-engine OK\n";
