<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use Psr\Log\LoggerInterface;

/**
 * wg-easy session client for the native metrics poller (P2).
 * Config path tries wg-easy v14+ `/api/client/{id}/configuration` first.
 */
class WgEasyClient
{
	/** @var list<string> */
	private array $cookies = [];

	public function __construct(
		private AppSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function login(): bool
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/');
		$result = $this->postJson($url . '/api/session', [
			'username' => $this->settings->getWgEasyUsername(),
			'password' => $this->settings->getWgEasyPassword(),
			'remember' => true,
		]);
		if ($result['http_code'] === 200) {
			$this->cookies = $result['cookies'];
			$this->logger->info('nc_wireguard: logged in to wg-easy');
			return true;
		}
		$this->logger->error('nc_wireguard: wg-easy login failed', [
			'http_code' => $result['http_code'],
			'error' => $result['error'],
		]);
		return false;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function getClients(): ?array
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/');
		$result = $this->getWithCookies($url . '/api/client');
		if ($result['http_code'] === 401 && $this->login()) {
			$result = $this->getWithCookies($url . '/api/client');
		}
		if ($result['http_code'] !== 200 || $result['body'] === false) {
			$this->logger->error('nc_wireguard: getClients failed', [
				'http_code' => $result['http_code'],
				'error' => $result['error'],
			]);
			return null;
		}
		$data = json_decode((string) $result['body'], true);
		return is_array($data) ? $data : null;
	}

	/**
	 * @return array{ok: bool, http_code: int, body: string|false, error: string, is_json: bool}
	 */
	public function getClientConfiguration(int $clientId): array
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/');
		$paths = [
			$url . '/api/client/' . $clientId . '/configuration',
			$url . '/api/wireguard/client/' . $clientId . '/configuration',
		];
		foreach ($paths as $path) {
			$result = $this->getWithCookies($path);
			if ($result['http_code'] === 401 && $this->login()) {
				$result = $this->getWithCookies($path);
			}
			if ($result['http_code'] === 200 && $result['body'] !== false) {
				$ct = $result['content_type'] ?? '';
				return [
					'ok' => true,
					'http_code' => 200,
					'body' => $result['body'],
					'error' => '',
					'is_json' => str_contains(strtolower($ct), 'json'),
				];
			}
			if ($result['http_code'] !== 404) {
				break;
			}
		}
		return [
			'ok' => false,
			'http_code' => $result['http_code'] ?? 0,
			'body' => $result['body'] ?? false,
			'error' => $result['error'] ?? 'configuration fetch failed',
			'is_json' => false,
		];
	}

	/**
	 * Normalize wg-easy configuration response to sidecar-compatible JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function formatConfigurationBody(string $body, bool $isJson): array
	{
		if ($isJson) {
			$data = json_decode($body, true);
			if (is_array($data)) {
				return $data;
			}
		}
		return ['configuration' => $body];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{http_code: int, error: string, cookies: list<string>, body: string|false}
	 */
	private function postJson(string $url, array $payload): array
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload) ?: '{}');
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);

		if ($response === false) {
			return ['http_code' => $httpCode, 'error' => $error, 'cookies' => [], 'body' => false];
		}

		$headerText = substr((string) $response, 0, $headerSize);
		$body = substr((string) $response, $headerSize);

		return [
			'http_code' => $httpCode,
			'error' => '',
			'cookies' => $this->parseSetCookieHeaders($headerText),
			'body' => $body,
		];
	}

	/**
	 * @return array{http_code: int, error: string, body: string|false, content_type: string}
	 */
	private function getWithCookies(string $url): array
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
		if ($this->cookies !== []) {
			curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $this->cookies));
		}
		$body = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);

		return [
			'http_code' => $httpCode,
			'error' => $error,
			'body' => $body,
			'content_type' => $contentType,
		];
	}

	/** @return list<string> */
	private function parseSetCookieHeaders(string $headerText): array
	{
		$cookies = [];
		foreach (preg_split('/\r\n|\n|\r/', $headerText) ?: [] as $line) {
			if (stripos($line, 'Set-Cookie:') !== 0) {
				continue;
			}
			$value = trim(substr($line, strlen('Set-Cookie:')));
			$semi = strpos($value, ';');
			if ($semi !== false) {
				$value = substr($value, 0, $semi);
			}
			if ($value !== '') {
				$cookies[] = $value;
			}
		}
		return $cookies;
	}
}
