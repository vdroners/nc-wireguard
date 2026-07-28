<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use Psr\Log\LoggerInterface;

/**
 * HTTP client for the wg-sync sidecar (P5).
 *
 * Deliberately dumb: it authenticates, it sends JSON, it decodes JSON, and it
 * never decides what a peer should look like. Everything about peer shape lives
 * in `NativeEngine` and `PeerConfBuilder`.
 *
 * Results keep the `{ok, http_code, error, ...}` envelope the engine interface
 * already uses, so callers do not need to know whether they are talking to
 * wg-easy or to a socket on the Docker network.
 */
class WgSyncClient
{
	public const ERR_NOT_CONFIGURED = 'WG_SYNC_NOT_CONFIGURED';

	public function __construct(
		private AppSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(): bool
	{
		return $this->settings->getWgSyncUrl() !== '' && $this->settings->getWgSyncToken() !== '';
	}

	/**
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>|null}
	 */
	public function health(): array
	{
		return $this->request('GET', '/health');
	}

	/**
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>|null}
	 */
	public function dump(): array
	{
		return $this->request('GET', '/dump');
	}

	/**
	 * @param array<string, mixed> $payload interface keys plus the full peer set
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>|null}
	 */
	public function apply(array $payload): array
	{
		return $this->request('POST', '/apply', $payload);
	}

	/**
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>|null}
	 */
	public function reload(): array
	{
		return $this->request('POST', '/reload', []);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array{ok: bool, http_code: int, error: string, json: array<string, mixed>|null}
	 */
	protected function request(string $method, string $path, ?array $body = null): array
	{
		$base = $this->settings->getWgSyncUrl();
		$token = $this->settings->getWgSyncToken();
		if ($base === '' || $token === '') {
			return [
				'ok' => false,
				'http_code' => 0,
				'error' => 'wg-sync URL or token is not configured',
				'json' => null,
			];
		}

		$ch = curl_init();
		$headers = [
			'Accept: application/json',
			'Authorization: Bearer ' . $token,
		];
		curl_setopt($ch, CURLOPT_URL, $base . $path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body) ?: '{}');
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$curlError = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false) {
			$this->logger->warning('nc_wireguard wg-sync: {method} {path} failed: {err}', [
				'method' => $method,
				'path' => $path,
				'err' => $curlError,
			]);
			return [
				'ok' => false,
				'http_code' => $httpCode,
				'error' => $curlError !== '' ? $curlError : 'wg-sync unreachable',
				'json' => null,
			];
		}

		$decoded = json_decode((string) $response, true);
		$json = is_array($decoded) ? $decoded : null;
		$ok = $httpCode >= 200 && $httpCode < 300 && ($json['ok'] ?? true) === true;
		$error = '';
		if (!$ok) {
			$error = is_string($json['error'] ?? null) && $json['error'] !== ''
				? $json['error']
				: 'wg-sync returned HTTP ' . $httpCode;
		}

		return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $error, 'json' => $json];
	}
}
