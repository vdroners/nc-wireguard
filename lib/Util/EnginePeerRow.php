<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Util;

/**
 * Translates engine-shaped peer records into the flat row the NC peer store
 * writes, and back-fills that row from a downloaded `.conf`.
 *
 * Two sources have to agree: wg-easy's JSON (camelCase, list endpoints omit
 * private keys) and the `.conf` body from `export-peers.sh` (INI-ish, carries
 * the private key and pre-shared key). Both land on the same keys here so the
 * importer and the store only ever see one shape.
 *
 * Secret values (`private_key`, `psk`) travel in the row but must never be
 * logged or printed.
 */
final class EnginePeerRow
{
	/** Keepalive the Field preset ships; wg-easy's default of 0 breaks NAT traversal. */
	public const FIELD_KEEPALIVE = 25;

	/** Full-tunnel break-glass peer that must survive every import untouched. */
	public const BREAK_GLASS_NAME = 'Server';

	/** Amnezia obfuscation knobs; a native `wg` backend cannot honour these. */
	private const AMNEZIA_KEYS = ['jc', 'jmin', 'jmax', 'i1', 'i2', 'i3', 'i4', 'i5'];

	/**
	 * @param array<string, mixed> $peer wg-easy client record (list or get-one)
	 * @return array<string, mixed>
	 */
	public static function fromEnginePeer(array $peer): array
	{
		$name = self::str($peer, ['name']) ?? '';
		$ipv4 = self::str($peer, ['ipv4Address', 'ipv4', 'address']);

		return [
			'wg_easy_id' => self::int($peer, ['id', 'peer_id']),
			'name' => $name,
			'public_key' => self::str($peer, ['publicKey', 'public_key']) ?? '',
			'enabled' => self::bool($peer, ['enabled'], true),
			'ipv4' => $ipv4 === null ? null : self::withPrefix($ipv4),
			'allowed_ips' => self::joined($peer, ['allowedIps', 'allowed_ips']),
			'dns' => self::joined($peer, ['dns']),
			'mtu' => self::int($peer, ['mtu']),
			'persistent_keepalive' => self::int($peer, ['persistentKeepalive', 'persistent_keepalive']),
			'server_endpoint' => self::str($peer, ['serverEndpoint', 'server_endpoint']),
			'server_allowed_ips' => self::joined($peer, ['serverAllowedIps', 'server_allowed_ips']),
			'firewall_ips' => self::joined($peer, ['firewallIps', 'firewall_ips']),
			'has_amnezia' => self::hasAmnezia($peer),
			'private_key' => self::str($peer, ['privateKey', 'private_key']),
			'psk' => self::str($peer, ['preSharedKey', 'presharedKey', 'preshared_key', 'psk']),
			'break_glass' => strcasecmp($name, self::BREAK_GLASS_NAME) === 0,
		];
	}

	/**
	 * Peer-relevant fields out of a WireGuard `.conf`.
	 *
	 * Only `[Interface]` keys plus the first `[Peer]` section are read — the
	 * server public key and endpoint belong to `nc_wg_server`, not to the peer.
	 *
	 * @return array<string, mixed>
	 */
	public static function fromConf(string $conf): array
	{
		$section = '';
		$interface = [];
		$peer = [];
		foreach (preg_split('/\R/', $conf) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
				continue;
			}
			if (preg_match('/^\[(\w+)\]$/', $line, $m) === 1) {
				$section = strtolower($m[1]);
				continue;
			}
			if (!str_contains($line, '=')) {
				continue;
			}
			[$key, $value] = explode('=', $line, 2);
			$key = strtolower(trim($key));
			$value = trim($value);
			if ($section === 'interface') {
				$interface[$key] = $value;
			} elseif ($section === 'peer' && !isset($peer[$key])) {
				$peer[$key] = $value;
			}
		}

		$row = [];
		if (isset($interface['address'])) {
			$ipv4 = self::firstIpv4($interface['address']);
			if ($ipv4 !== null) {
				$row['ipv4'] = self::withPrefix($ipv4);
			}
		}
		if (isset($interface['privatekey']) && $interface['privatekey'] !== '') {
			$row['private_key'] = $interface['privatekey'];
		}
		if (isset($interface['dns']) && $interface['dns'] !== '') {
			$row['dns'] = self::joinList($interface['dns']);
		}
		if (isset($interface['mtu']) && preg_match('/^\d+$/', $interface['mtu']) === 1) {
			$row['mtu'] = (int) $interface['mtu'];
		}
		if (isset($peer['presharedkey']) && $peer['presharedkey'] !== '') {
			$row['psk'] = $peer['presharedkey'];
		}
		if (isset($peer['allowedips']) && $peer['allowedips'] !== '') {
			$row['allowed_ips'] = self::joinList($peer['allowedips']);
		}
		if (isset($peer['endpoint']) && $peer['endpoint'] !== '') {
			$row['server_endpoint'] = $peer['endpoint'];
		}
		if (isset($peer['persistentkeepalive']) && preg_match('/^\d+$/', $peer['persistentkeepalive']) === 1) {
			$row['persistent_keepalive'] = (int) $peer['persistentkeepalive'];
		}
		return $row;
	}

	/**
	 * Overlay wins, but only where it actually carries a value — a `.conf` that
	 * omits DNS must not blank the value the engine reported.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $overlay
	 * @return array<string, mixed>
	 */
	public static function merge(array $base, array $overlay): array
	{
		foreach ($overlay as $key => $value) {
			if ($value === null || $value === '' || $value === []) {
				continue;
			}
			$base[$key] = $value;
		}
		return $base;
	}

	/**
	 * Operator-facing warnings about a row. No secret values, ever.
	 *
	 * @param array<string, mixed> $row
	 * @return list<string>
	 */
	public static function notesFor(array $row): array
	{
		$notes = [];
		$keepalive = $row['persistent_keepalive'] ?? null;
		if ($keepalive === null || (int) $keepalive === 0) {
			$notes[] = 'keepalive=0 (engine default) — Field peers need '
				. self::FIELD_KEEPALIVE . 's to hold NAT open';
		}
		if (($row['private_key'] ?? null) === null) {
			$notes[] = 'no private key in this source — re-run with engine get-one or an export dir';
		}
		if (($row['has_amnezia'] ?? false) === true) {
			$notes[] = 'Amnezia obfuscation set — NativeEngine must refuse this peer, not drop the fields';
		}
		if (($row['ipv4'] ?? null) === null) {
			$notes[] = 'no tunnel address in this source — IPAM will assign one';
		}
		if (($row['break_glass'] ?? false) === true) {
			$notes[] = 'break-glass full-tunnel peer — preserved as-is';
		}
		return $notes;
	}

	/**
	 * @param array<string, mixed> $peer
	 */
	private static function hasAmnezia(array $peer): bool
	{
		$lowered = [];
		foreach ($peer as $key => $value) {
			if (is_string($key)) {
				$lowered[strtolower($key)] = $value;
			}
		}
		foreach (self::AMNEZIA_KEYS as $key) {
			$value = $lowered[$key] ?? null;
			if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $peer
	 * @param list<string> $keys
	 */
	private static function str(array $peer, array $keys): ?string
	{
		foreach ($keys as $key) {
			$value = $peer[$key] ?? null;
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $peer
	 * @param list<string> $keys
	 */
	private static function int(array $peer, array $keys): ?int
	{
		foreach ($keys as $key) {
			$value = $peer[$key] ?? null;
			if (is_int($value)) {
				return $value;
			}
			if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
				return (int) trim($value);
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $peer
	 * @param list<string> $keys
	 */
	private static function bool(array $peer, array $keys, bool $default): bool
	{
		foreach ($keys as $key) {
			if (!array_key_exists($key, $peer)) {
				continue;
			}
			$value = $peer[$key];
			if (is_bool($value)) {
				return $value;
			}
			if (is_int($value)) {
				return $value !== 0;
			}
			if (is_string($value)) {
				return !in_array(strtolower(trim($value)), ['0', 'false', 'no', ''], true);
			}
		}
		return $default;
	}

	/**
	 * Comma-joined string for the store's single text column.
	 *
	 * @param array<string, mixed> $peer
	 * @param list<string> $keys
	 */
	private static function joined(array $peer, array $keys): ?string
	{
		foreach ($keys as $key) {
			$value = $peer[$key] ?? null;
			if (is_array($value)) {
				$parts = [];
				foreach ($value as $entry) {
					if (is_string($entry) && trim($entry) !== '') {
						$parts[] = trim($entry);
					}
				}
				if ($parts !== []) {
					return implode(', ', $parts);
				}
				continue;
			}
			if (is_string($value) && trim($value) !== '') {
				return self::joinList($value);
			}
		}
		return null;
	}

	private static function joinList(string $raw): string
	{
		$parts = [];
		foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
			if (trim($entry) !== '') {
				$parts[] = trim($entry);
			}
		}
		return implode(', ', $parts);
	}

	private static function firstIpv4(string $raw): ?string
	{
		foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
			$candidate = trim($entry);
			if ($candidate === '') {
				continue;
			}
			$host = str_contains($candidate, '/') ? explode('/', $candidate, 2)[0] : $candidate;
			if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Store the peer's tunnel address as a single-host route.
	 *
	 * wg-easy writes the pool prefix into the `.conf` (`Address = 10.8.0.4/24`)
	 * while its API returns a bare address. Normalising both to `/32` keeps the
	 * column comparable for IPAM collision checks; peer-visible routes live in
	 * `allowed_ips`, not here.
	 */
	private static function withPrefix(string $address): string
	{
		$value = trim($address);
		if (str_contains($value, '/')) {
			$value = explode('/', $value, 2)[0];
		}
		return $value . '/32';
	}
}
