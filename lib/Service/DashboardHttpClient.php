<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * HTTP client helper for reaching wg-dashboard from cloud_app.
 */
class DashboardHttpClient
{
	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getBaseUrl(): string
	{
		$url = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_internal_url',
			'http://wg-dashboard:8185'
		));
		if ($url === '') {
			$url = 'http://wg-dashboard:8185';
		}
		return $this->resolveHostDockerInternal($url);
	}

	public function getConnectTimeoutSeconds(): int
	{
		$raw = (int) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_proxy_connect_timeout',
			'5'
		);
		return max(1, min(30, $raw));
	}

	public function getTimeoutSeconds(): int
	{
		$raw = (int) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_proxy_timeout',
			'30'
		);
		return max(5, min(120, $raw));
	}

	public function isEnabled(): bool
	{
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_enabled',
			'1'
		));
		return $raw === '1' || strtolower($raw) === 'true';
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

	/**
	 * @return array{ok: bool, http_code: int, body: string|false, error: string}
	 */
	public function get(string $urlPath, string $queryString = ''): array
	{
		$url = rtrim($this->getBaseUrl(), '/') . $urlPath;
		if ($queryString !== '') {
			$url .= '?' . $queryString;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->getTimeoutSeconds());
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->getConnectTimeoutSeconds());
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: application/json',
		]);
		$body = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		return [
			'ok' => ($body !== false && $error === ''),
			'http_code' => $httpCode,
			'body' => $body,
			'error' => $error,
		];
	}
}
