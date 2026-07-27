<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * wg-easy session client for metrics poller + peer controller (v2.1).
 *
 * Write contract (wg-easy v15):
 * - create: POST /api {name, expiresAt?}
 * - update: POST /api/client/{id} full ClientUpdateSchema
 * - delete: DELETE /api/client/{id}
 * - enable/disable: POST /api/client/{id}/enable|disable
 * - OTL: POST /api/client/{id}/generateOneTimeLink then read client.oneTimeLink
 */
class WgEasyClient
{
	public const ERR_TOTP_REQUIRED = 'TOTP_REQUIRED';

	/** @var list<string> */
	private array $cookies = [];

	public function __construct(
		private AppSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{ok: bool, error?: string, code?: string}
	 */
	public function login(): array
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/');
		$result = $this->request('POST', $url . '/api/session', [
			'username' => $this->settings->getWgEasyUsername(),
			'password' => $this->settings->getWgEasyPassword(),
			'remember' => true,
		], false);

		$bodyStr = is_string($result['body']) ? $result['body'] : '';
		$decoded = json_decode($bodyStr, true);
		if (is_array($decoded)) {
			$status = (string) ($decoded['status'] ?? $decoded['error'] ?? $decoded['code'] ?? '');
			if (stripos($status, 'TOTP') !== false || !empty($decoded['totp'])) {
				$this->logger->error('nc_wireguard: wg-easy login requires TOTP (disable 2FA on API user)');
				return ['ok' => false, 'error' => 'wg-easy API user has TOTP enabled', 'code' => self::ERR_TOTP_REQUIRED];
			}
		}

		if ($result['http_code'] === 200) {
			$this->cookies = $result['cookies'];
			$this->logger->info('nc_wireguard: logged in to wg-easy');
			return ['ok' => true];
		}

		$this->logger->error('nc_wireguard: wg-easy login failed', [
			'http_code' => $result['http_code'],
			'error' => $result['error'],
		]);
		return ['ok' => false, 'error' => $result['error'] !== '' ? $result['error'] : 'login failed'];
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function getClients(): ?array
	{
		$result = $this->authedJson('GET', '/api/client');
		if ($result['http_code'] !== 200 || !is_array($result['json'])) {
			$this->logger->error('nc_wireguard: getClients failed', [
				'http_code' => $result['http_code'],
				'error' => $result['error'],
			]);
			return null;
		}
		return array_values($result['json']);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getClient(int $clientId): ?array
	{
		$result = $this->authedJson('GET', '/api/client/' . $clientId);
		if ($result['http_code'] !== 200 || !is_array($result['json'])) {
			return null;
		}
		return $result['json'];
	}

	/**
	 * @param array{name: string, expiresAt?: string|null} $fields
	 * @return array{ok: bool, http_code: int, clientId?: int, error?: string, code?: string, body?: mixed}
	 */
	public function createClient(array $fields): array
	{
		$payload = [
			'name' => (string) $fields['name'],
			// wg-easy v15 zod requires the key; null = no expiry.
			'expiresAt' => array_key_exists('expiresAt', $fields) ? $fields['expiresAt'] : null,
		];
		$result = $this->authedJson('POST', '/api/client', $payload);
		if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
			$json = $result['json'];
			$clientId = null;
			if (is_array($json)) {
				$clientId = $json['clientId'] ?? $json['id'] ?? null;
				if ($clientId === null && isset($json[0]['clientId'])) {
					$clientId = $json[0]['clientId'];
				}
			}
			return [
				'ok' => true,
				'http_code' => $result['http_code'],
				'clientId' => is_numeric($clientId) ? (int) $clientId : null,
				'body' => $json,
			];
		}
		return $this->failResult($result);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{ok: bool, http_code: int, error?: string, code?: string, body?: mixed}
	 */
	public function updateClient(int $clientId, array $fields): array
	{
		$existing = $this->getClient($clientId);
		if ($existing === null) {
			return ['ok' => false, 'http_code' => 404, 'error' => 'client not found'];
		}
		$payload = $this->mergeUpdatePayload($existing, $fields);
		$result = $this->authedJson('POST', '/api/client/' . $clientId, $payload);
		if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
			return ['ok' => true, 'http_code' => $result['http_code'], 'body' => $result['json']];
		}
		return $this->failResult($result);
	}

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function deleteClient(int $clientId): array
	{
		$result = $this->authedJson('DELETE', '/api/client/' . $clientId);
		if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
			return ['ok' => true, 'http_code' => $result['http_code']];
		}
		return $this->failResult($result);
	}

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function enableClient(int $clientId): array
	{
		$result = $this->authedJson('POST', '/api/client/' . $clientId . '/enable');
		if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
			return ['ok' => true, 'http_code' => $result['http_code']];
		}
		return $this->failResult($result);
	}

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function disableClient(int $clientId): array
	{
		$result = $this->authedJson('POST', '/api/client/' . $clientId . '/disable');
		if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
			return ['ok' => true, 'http_code' => $result['http_code']];
		}
		return $this->failResult($result);
	}

	/**
	 * @return array{ok: bool, http_code: int, oneTimeLink?: string|null, redeemPath?: string|null, expiresAt?: string|null, error?: string, code?: string}
	 */
	public function generateOneTimeLink(int $clientId): array
	{
		$result = $this->authedJson('POST', '/api/client/' . $clientId . '/generateOneTimeLink');
		if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
			return $this->failResult($result);
		}
		// wg-easy returns only {success: true} here, and its single-client
		// endpoint (drizzle findById) does not join the one_time_links_table.
		// Only the list queries carry the nested relation, so read it back there.
		$token = null;
		$expiresAt = null;
		foreach ($this->getClients() ?? [] as $candidate) {
			if (!is_array($candidate) || (int) ($candidate['id'] ?? 0) !== $clientId) {
				continue;
			}
			[$token, $expiresAt] = self::extractOneTimeLink($candidate);
			break;
		}
		return [
			'ok' => true,
			'http_code' => $result['http_code'],
			'oneTimeLink' => $token,
			'redeemPath' => $token !== null ? '/cnf/' . $token : null,
			'expiresAt' => $expiresAt,
		];
	}

	/**
	 * Pull the one-time-link token and its expiry out of a wg-easy client record.
	 *
	 * v15 nests them as {oneTimeLink: {oneTimeLink: "abc123", expiresAt: "..."}};
	 * older shapes exposed a bare string. Both are accepted.
	 *
	 * @param array<string, mixed> $client
	 * @return array{0: string|null, 1: string|null} [token, expiresAt]
	 */
	public static function extractOneTimeLink(array $client): array
	{
		$raw = $client['oneTimeLink'] ?? null;
		if (is_string($raw)) {
			return [$raw !== '' ? $raw : null, null];
		}
		if (is_array($raw)) {
			$token = $raw['oneTimeLink'] ?? null;
			if (!is_string($token) || $token === '') {
				// No usable token: an expiry on its own would be misleading.
				return [null, null];
			}
			$expiresAt = $raw['expiresAt'] ?? null;
			return [$token, is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : null];
		}
		return [null, null];
	}

	/**
	 * Fetch one-shot config from wg-easy /cnf/{token} (no session).
	 *
	 * @return array{ok: bool, http_code: int, body: string|false, error: string, content_type: string}
	 */
	public function redeemOneTimeLink(string $token): array
	{
		$token = trim($token);
		if ($token === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
			return ['ok' => false, 'http_code' => 400, 'body' => false, 'error' => 'invalid token', 'content_type' => ''];
		}
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/') . '/cnf/' . rawurlencode($token);
		$result = $this->request('GET', $url, null, false);
		$ok = $result['http_code'] === 200 && is_string($result['body']) && $result['body'] !== '';
		return [
			'ok' => $ok,
			'http_code' => $result['http_code'],
			'body' => $result['body'],
			'error' => $ok ? '' : ($result['error'] !== '' ? $result['error'] : 'redeem failed'),
			'content_type' => $result['content_type'] ?? '',
		];
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
		$result = ['http_code' => 0, 'body' => false, 'error' => '', 'content_type' => ''];
		foreach ($paths as $path) {
			$result = $this->request('GET', $path, null, true);
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
	 * @param array<string, mixed> $existing
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function mergeUpdatePayload(array $existing, array $fields): array
	{
		$get = static function (array $src, string $key, mixed $default = null): mixed {
			return array_key_exists($key, $src) ? $src[$key] : $default;
		};

		$allowedIps = $get($fields, 'allowedIps', $get($existing, 'allowedIps'));
		$dns = $get($fields, 'dns', $get($existing, 'dns'));

		return [
			'name' => (string) $get($fields, 'name', $get($existing, 'name', '')),
			'enabled' => (bool) $get($fields, 'enabled', $get($existing, 'enabled', true)),
			'expiresAt' => $get($fields, 'expiresAt', $get($existing, 'expiresAt')),
			'ipv4Address' => $get($fields, 'ipv4Address', $get($existing, 'ipv4Address')),
			'ipv6Address' => $get($fields, 'ipv6Address', $get($existing, 'ipv6Address')),
			'preUp' => (string) ($get($fields, 'preUp', $get($existing, 'preUp', '')) ?? ''),
			'postUp' => (string) ($get($fields, 'postUp', $get($existing, 'postUp', '')) ?? ''),
			'preDown' => (string) ($get($fields, 'preDown', $get($existing, 'preDown', '')) ?? ''),
			'postDown' => (string) ($get($fields, 'postDown', $get($existing, 'postDown', '')) ?? ''),
			'allowedIps' => $this->normalizeStringList($allowedIps),
			'serverAllowedIps' => $this->normalizeStringList($get($fields, 'serverAllowedIps', $get($existing, 'serverAllowedIps', []))) ?? [],
			'firewallIps' => $get($fields, 'firewallIps', $get($existing, 'firewallIps')),
			'mtu' => (int) $get($fields, 'mtu', $get($existing, 'mtu', 1420)),
			'jC' => $get($fields, 'jC', $get($existing, 'jC')),
			'jMin' => $get($fields, 'jMin', $get($existing, 'jMin')),
			'jMax' => $get($fields, 'jMax', $get($existing, 'jMax')),
			'i1' => $get($fields, 'i1', $get($existing, 'i1')),
			'i2' => $get($fields, 'i2', $get($existing, 'i2')),
			'i3' => $get($fields, 'i3', $get($existing, 'i3')),
			'i4' => $get($fields, 'i4', $get($existing, 'i4')),
			'i5' => $get($fields, 'i5', $get($existing, 'i5')),
			'persistentKeepalive' => (int) $get($fields, 'persistentKeepalive', $get($existing, 'persistentKeepalive', 0)),
			'serverEndpoint' => $get($fields, 'serverEndpoint', $get($existing, 'serverEndpoint')),
			'dns' => $this->normalizeStringList($dns),
		];
	}

	/**
	 * @param mixed $value
	 * @return list<string>|null
	 */
	private function normalizeStringList(mixed $value): ?array
	{
		if ($value === null) {
			return null;
		}
		if (is_string($value)) {
			$parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
			$parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
			return $parts;
		}
		if (!is_array($value)) {
			return null;
		}
		$out = [];
		foreach ($value as $item) {
			if (is_string($item) && trim($item) !== '') {
				$out[] = trim($item);
			}
		}
		return $out;
	}

	/**
	 * @param array{http_code: int, error: string, json: mixed, code?: string} $result
	 * @return array{ok: bool, http_code: int, error: string, code?: string, body?: mixed}
	 */
	private function failResult(array $result): array
	{
		$out = [
			'ok' => false,
			'http_code' => $result['http_code'],
			'error' => $result['error'] !== '' ? $result['error'] : 'request failed',
			'body' => $result['json'],
		];
		if (!empty($result['code'])) {
			$out['code'] = $result['code'];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $jsonBody
	 * @return array{http_code: int, error: string, json: mixed, code?: string}
	 */
	private function authedJson(string $method, string $path, ?array $jsonBody = null): array
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/') . $path;
		$result = $this->request($method, $url, $jsonBody, true);
		if ($result['http_code'] === 401) {
			$login = $this->login();
			if (!$login['ok']) {
				return [
					'http_code' => 401,
					'error' => $login['error'] ?? 're-login failed',
					'json' => null,
					'code' => $login['code'] ?? null,
				];
			}
			$result = $this->request($method, $url, $jsonBody, true);
		}
		$json = null;
		if (is_string($result['body']) && $result['body'] !== '') {
			$decoded = json_decode($result['body'], true);
			$json = is_array($decoded) ? $decoded : $result['body'];
		}
		$error = $result['error'];
		$code = null;
		if (is_array($json)) {
			if (isset($json['message']) && is_string($json['message'])) {
				$error = $json['message'];
			} elseif (isset($json['statusMessage']) && is_string($json['statusMessage'])) {
				$error = $json['statusMessage'];
			}
			$status = (string) ($json['status'] ?? $json['statusCode'] ?? '');
			if (stripos($status, 'TOTP') !== false || stripos($error, 'TOTP') !== false) {
				$code = self::ERR_TOTP_REQUIRED;
			}
		}
		$out = [
			'http_code' => $result['http_code'],
			'error' => $error,
			'json' => $json,
		];
		if ($code !== null) {
			$out['code'] = $code;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $jsonBody
	 * @return array{http_code: int, error: string, cookies: list<string>, body: string|false, content_type: string}
	 */
	private function request(string $method, string $url, ?array $jsonBody, bool $withCookies): array
	{
		$ch = curl_init();
		$headers = ['Accept: application/json'];
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		if ($jsonBody !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody) ?: '{}');
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if ($withCookies && $this->cookies !== []) {
			curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $this->cookies));
		}
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);

		if ($response === false) {
			return [
				'http_code' => $httpCode,
				'error' => $error,
				'cookies' => [],
				'body' => false,
				'content_type' => $contentType,
			];
		}

		$headerText = substr((string) $response, 0, $headerSize);
		$body = substr((string) $response, $headerSize);
		$cookies = $this->parseSetCookieHeaders($headerText);
		if ($cookies !== []) {
			$this->cookies = $cookies;
		}

		return [
			'http_code' => $httpCode,
			'error' => '',
			'cookies' => $cookies,
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
