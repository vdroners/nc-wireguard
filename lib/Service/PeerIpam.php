<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Db\ServerConfigMapper;
use RuntimeException;

/**
 * Hands out tunnel addresses out of the NC-owned pool.
 *
 * wg-easy allocates addresses itself, so while `engine=wgeasy` this only fills
 * the gaps for peers that arrive without one. Once NC owns the dataplane it is
 * the sole allocator, which is why collisions are checked against the stored
 * peers rather than against anything the engine reports.
 *
 * IPv4 only by design (P3 policy): no IPv6 is assigned.
 */
class PeerIpam
{
	public const DEFAULT_CIDR = '10.8.0.0/24';

	/** The server itself always takes the first host address (`.1`). */
	public const SERVER_HOST_OFFSET = 1;

	/**
	 * A /16 is 65534 candidates, which is the widest linear scan worth doing in
	 * a request. Anything wider is a configuration mistake, not a real pool.
	 */
	private const MIN_PREFIX = 16;
	private const MAX_PREFIX = 30;

	public function __construct(
		private PeerMapper $peers,
		private ServerConfigMapper $server,
	) {
	}

	/**
	 * Configured tunnel pool, falling back to the wg-easy-compatible default.
	 */
	public function poolCidr(): string
	{
		$config = $this->server->load();
		$cidr = $config?->getCidr();
		return is_string($cidr) && trim($cidr) !== '' ? trim($cidr) : self::DEFAULT_CIDR;
	}

	/**
	 * Next free address in the pool as a `/32`, e.g. `10.8.0.5/32`.
	 *
	 * @throws RuntimeException when the pool is malformed or exhausted
	 */
	public function allocate(): string
	{
		return $this->nextFree($this->poolCidr(), $this->peers->findAssignedIpv4());
	}

	/**
	 * Pure allocation core: first host address in `$cidr` that is neither the
	 * reserved server address nor present in `$taken`.
	 *
	 * `$taken` accepts bare addresses and `address/prefix` strings so it can be
	 * fed straight from `nc_wg_peers.ipv4`.
	 *
	 * @param list<string> $taken
	 * @throws RuntimeException
	 */
	public function nextFree(string $cidr, array $taken): string
	{
		[$network, $prefix] = $this->parseCidr($cidr);

		$reserved = [];
		foreach ($taken as $entry) {
			$host = self::hostOf($entry);
			if ($host !== null) {
				$reserved[$host] = true;
			}
		}
		$reserved[$this->serverAddress($cidr)] = true;

		$first = $network + 1;
		$last = $network + (1 << (32 - $prefix)) - 2;
		for ($candidate = $first; $candidate <= $last; $candidate++) {
			$address = long2ip($candidate);
			if (!isset($reserved[$address])) {
				return $address . '/32';
			}
		}

		throw new RuntimeException('WireGuard address pool ' . $cidr . ' is exhausted');
	}

	/**
	 * Reserved server address for a pool (`10.8.0.1` for `10.8.0.0/24`).
	 *
	 * @throws RuntimeException
	 */
	public function serverAddress(string $cidr): string
	{
		[$network] = $this->parseCidr($cidr);
		return long2ip($network + self::SERVER_HOST_OFFSET);
	}

	public function isInPool(string $address, string $cidr): bool
	{
		$host = self::hostOf($address);
		if ($host === null) {
			return false;
		}
		[$network, $prefix] = $this->parseCidr($cidr);
		return (ip2long($host) & self::maskOf($prefix)) === $network;
	}

	/**
	 * Bare IPv4 address from `10.8.0.5` or `10.8.0.5/32`; null when unusable.
	 */
	public static function hostOf(string $entry): ?string
	{
		$value = trim($entry);
		if ($value === '') {
			return null;
		}
		if (str_contains($value, '/')) {
			$value = explode('/', $value, 2)[0];
		}
		return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $value;
	}

	/**
	 * @return array{0: int, 1: int} network address as a long, prefix length
	 * @throws RuntimeException
	 */
	private function parseCidr(string $cidr): array
	{
		$parts = explode('/', trim($cidr), 2);
		$address = $parts[0] ?? '';
		$prefix = isset($parts[1]) && preg_match('/^\d{1,2}$/', $parts[1]) === 1 ? (int) $parts[1] : -1;

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			throw new RuntimeException('Not an IPv4 CIDR: ' . $cidr);
		}
		if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
			throw new RuntimeException(
				'Tunnel pool prefix must be between /' . self::MIN_PREFIX
				. ' and /' . self::MAX_PREFIX . ': ' . $cidr
			);
		}

		return [ip2long($address) & self::maskOf($prefix), $prefix];
	}

	private static function maskOf(int $prefix): int
	{
		return (-1 << (32 - $prefix)) & 0xFFFFFFFF;
	}
}
