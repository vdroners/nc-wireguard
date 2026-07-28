<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Server-side source of truth for the Field / Admin peer presets (P4).
 *
 * These values used to exist only in `src/services/dashboard-api.js`, which
 * meant the browser decided what a "Field" peer looks like while IPAM and the
 * `.conf` builder were guessing. Keeping them in PHP lets `PeerConfBuilder`
 * fill gaps with the same numbers the UI offers, and lets the server row
 * (`nc_wg_server`) override them per deployment.
 *
 * The JS constants mirror this table; `docs/ops/NATIVE_CONF_DEFAULTS.md`
 * documents the precedence rules.
 */
final class PeerPresets
{
	public const FIELD = 'field';
	public const ADMIN = 'admin';

	/**
	 * Split tunnel for field / site GCS peers: the site LAN plus the tunnel
	 * itself, and a keepalive that actually holds a CGNAT pinhole open.
	 *
	 * @var array{allowed_ips: string, dns: string|null, mtu: int, persistent_keepalive: int}
	 */
	public const PRESET_FIELD = [
		'allowed_ips' => '10.0.0.0/24, 10.8.0.0/24',
		'dns' => null,
		'mtu' => 1420,
		'persistent_keepalive' => 25,
	];

	/**
	 * Full tunnel for administrators. IPv4 only — `::/0` is never emitted
	 * while `nc_wg_server.ipv4_only` is set (the P3 policy decision).
	 *
	 * @var array{allowed_ips: string, dns: string|null, mtu: int, persistent_keepalive: int}
	 */
	public const PRESET_ADMIN = [
		'allowed_ips' => '0.0.0.0/0',
		'dns' => '1.1.1.1',
		'mtu' => 1420,
		'persistent_keepalive' => 25,
	];

	/**
	 * @return array{allowed_ips: string, dns: string|null, mtu: int, persistent_keepalive: int}|null
	 */
	public static function byName(string $name): ?array
	{
		return match (strtolower(trim($name))) {
			self::FIELD => self::PRESET_FIELD,
			self::ADMIN => self::PRESET_ADMIN,
			default => null,
		};
	}

	/** @return list<string> */
	public static function names(): array
	{
		return [self::FIELD, self::ADMIN];
	}
}
