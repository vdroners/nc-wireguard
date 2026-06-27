<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Util;

/**
 * Extract host IP from a WireGuard endpoint string (IPv4 host:port or [ipv6]:port).
 */
final class EndpointIp
{
	public static function parse(?string $endpoint): ?string
	{
		if ($endpoint === null || trim($endpoint) === '') {
			return null;
		}
		$endpoint = trim($endpoint);
		if (str_starts_with($endpoint, '[')) {
			$end = strpos($endpoint, ']');
			if ($end === false) {
				return null;
			}
			return substr($endpoint, 1, $end - 1);
		}
		if (substr_count($endpoint, ':') > 1 && !str_contains(explode(':', $endpoint)[0], '.')) {
			return substr($endpoint, 0, strrpos($endpoint, ':'));
		}
		$pos = strrpos($endpoint, ':');
		if ($pos === false) {
			return $endpoint;
		}
		return substr($endpoint, 0, $pos);
	}
}
