<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Util;

use Psr\Log\LoggerInterface;

/**
 * Rewrites host.docker.internal when DNS fails inside cloud_app.
 */
class DockerUrlResolver
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function resolveHostDockerInternal(string $url): string
	{
		static $cache = [];
		if (isset($cache[$url])) {
			return $cache[$url];
		}
		$parts = @parse_url($url);
		if (!is_array($parts) || empty($parts['host'])) {
			return $cache[$url] = $url;
		}
		$host = $parts['host'];
		if (strtolower($host) !== 'host.docker.internal') {
			return $cache[$url] = $url;
		}
		$resolved = gethostbyname($host);
		if ($resolved && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
			return $cache[$url] = $url;
		}
		$gateway = $this->detectBridgeGateway();
		if (!$gateway) {
			return $cache[$url] = $url;
		}
		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		$scheme = $parts['scheme'] ?? 'http';
		$path = $parts['path'] ?? '';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';
		$rewritten = $scheme . '://' . $gateway . $port . $path . $query;
		$this->logger->debug(
			'nc_wireguard: host.docker.internal unresolved, using bridge gateway ' . $gateway
		);
		return $cache[$url] = $rewritten;
	}

	private function detectBridgeGateway(): ?string
	{
		$path = '/proc/net/route';
		if (!is_readable($path)) {
			return null;
		}
		$lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return null;
		}
		foreach ($lines as $idx => $line) {
			if ($idx === 0) {
				continue;
			}
			$fields = preg_split('/\s+/', $line);
			if (!is_array($fields) || count($fields) < 4) {
				continue;
			}
			$dest = $fields[1] ?? '';
			$gw = $fields[2] ?? '';
			if ($dest !== '00000000' || strlen($gw) !== 8) {
				continue;
			}
			$bytes = [
				hexdec(substr($gw, 6, 2)),
				hexdec(substr($gw, 4, 2)),
				hexdec(substr($gw, 2, 2)),
				hexdec(substr($gw, 0, 2)),
			];
			$ip = implode('.', $bytes);
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		}
		return null;
	}
}
