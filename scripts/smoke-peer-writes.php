<?php

declare(strict_types=1);

/**
 * Service-layer smoke for the v2.1 peer write surface (no HTTP session required).
 *
 * Exercises the full wg-easy write contract — create, update, disable, enable,
 * one-time link, configuration, redeem, delete — against the live engine. The
 * write paths are version-sensitive, so run this after any wg-easy image bump.
 *
 * Creates a temporary peer named zz-nc-smoke-<time> and always deletes it again.
 *
 * Run inside cloud_app:
 *   docker exec -u www-data cloud_app php \
 *     /var/www/html/custom_apps/nc_wireguard/scripts/smoke-peer-writes.php
 */

require '/var/www/html/lib/base.php';

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\WgEasyClient;

$client = \OC::$server->get(WgEasyClient::class);
$settings = \OC::$server->get(AppSettings::class);

$failures = 0;

/**
 * @param string $detail extra context printed after the verdict
 */
function step(string $label, bool $ok, string $detail = ''): void
{
	global $failures;
	if (!$ok) {
		$failures++;
	}
	printf("%-32s %-4s %s\n", $label, $ok ? 'OK' : 'FAIL', $detail);
}

echo 'wg-easy api url: ' . $settings->getWgEasyApiUrl() . "\n\n";

$login = $client->login();
if (!($login['ok'] ?? false)) {
	fwrite(STDERR, 'login failed: ' . ($login['code'] ?? '') . ' ' . ($login['error'] ?? '') . "\n");
	if (($login['code'] ?? '') === WgEasyClient::ERR_TOTP_REQUIRED) {
		fwrite(STDERR, "The wg-easy service account has 2FA enabled; API sessions cannot use TOTP.\n");
	}
	exit(1);
}
step('login', true);

$before = $client->getClients() ?? [];
step('list clients', is_array($before), 'count=' . count($before));

$name = 'zz-nc-smoke-' . date('His');
$clientId = null;

try {
	$created = $client->createClient(['name' => $name]);
	$clientId = $created['clientId'] ?? null;
	step('create', (bool) $created['ok'] && is_int($clientId), 'id=' . var_export($clientId, true));
	if (!is_int($clientId) || $clientId < 1) {
		throw new RuntimeException('create did not yield a client id');
	}

	// Tunnel fields cannot ride along on create; they need a follow-up update.
	$updated = $client->updateClient($clientId, [
		'allowedIps' => '10.0.0.0/24,10.8.0.0/24',
		'persistentKeepalive' => 25,
		'mtu' => 1420,
	]);
	step('update (tunnel fields)', (bool) $updated['ok'], 'http=' . $updated['http_code']);

	$record = $client->getClient($clientId) ?? [];
	step(
		'update persisted',
		(int) ($record['mtu'] ?? 0) === 1420 && (int) ($record['persistentKeepalive'] ?? 0) === 25,
		'mtu=' . var_export($record['mtu'] ?? null, true)
			. ' keepalive=' . var_export($record['persistentKeepalive'] ?? null, true)
			. ' allowedIps=' . json_encode($record['allowedIps'] ?? null)
	);

	$client->disableClient($clientId);
	$record = $client->getClient($clientId) ?? [];
	step('disable', ($record['enabled'] ?? true) == false);

	$client->enableClient($clientId);
	$record = $client->getClient($clientId) ?? [];
	step('enable', ($record['enabled'] ?? false) == true);

	$otl = $client->generateOneTimeLink($clientId);
	$token = $otl['oneTimeLink'] ?? null;
	step(
		'one-time link',
		(bool) $otl['ok'] && is_string($token) && $token !== '',
		'expiresAt=' . var_export($otl['expiresAt'] ?? null, true)
	);

	$cfg = $client->getClientConfiguration($clientId);
	$conf = (string) ($client->formatConfigurationBody(
		is_string($cfg['body']) ? $cfg['body'] : '',
		(bool) $cfg['is_json']
	)['configuration'] ?? '');
	step(
		'configuration',
		(bool) $cfg['ok'] && str_contains($conf, '[Interface]') && str_contains($conf, '[Peer]'),
		'bytes=' . strlen($conf)
	);

	if (is_string($token) && $token !== '') {
		$redeemed = $client->redeemOneTimeLink($token);
		step('redeem one-time link', (bool) $redeemed['ok'], 'bytes=' . strlen((string) $redeemed['body']));
	}
} catch (Throwable $e) {
	step('unexpected error', false, $e->getMessage());
} finally {
	if (is_int($clientId) && $clientId > 0) {
		$deleted = $client->deleteClient($clientId);
		step('delete (cleanup)', (bool) $deleted['ok'], 'http=' . $deleted['http_code']);
	}
	$leftover = 0;
	foreach ($client->getClients() ?? [] as $candidate) {
		if (str_starts_with((string) ($candidate['name'] ?? ''), 'zz-nc-smoke-')) {
			$leftover++;
		}
	}
	step('no leftover smoke peers', $leftover === 0, 'leftover=' . $leftover);
}

echo "\n" . ($failures === 0
	? "smoke-peer-writes OK\n"
	: "smoke-peer-writes FAILED ({$failures})\n");
exit($failures === 0 ? 0 : 1);
