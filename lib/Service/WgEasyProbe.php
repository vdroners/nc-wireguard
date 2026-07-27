<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Minimal wg-easy session probe for admin settings test button (P1).
 */
class WgEasyProbe
{
	public function __construct(
		private AppSettings $settings,
	) {
	}

	/**
	 * @return array{ok: bool, http_code: int, error: string, client_count: int|null}
	 */
	public function testSession(): array
	{
		$url = rtrim($this->settings->getWgEasyApiUrl(), '/');
		$username = $this->settings->getWgEasyUsername();
		$password = $this->settings->getWgEasyPassword();

		if ($url === '') {
			return ['ok' => false, 'http_code' => 0, 'error' => 'wg-easy API URL not configured', 'client_count' => null];
		}
		if ($username === '' || $password === '') {
			return ['ok' => false, 'http_code' => 0, 'error' => 'wg-easy credentials not configured', 'client_count' => null];
		}

		$session = $this->postJson($url . '/api/session', [
			'username' => $username,
			'password' => $password,
			'remember' => true,
		]);
		if (!$session['ok'] || $session['http_code'] !== 200) {
			return [
				'ok' => false,
				'http_code' => $session['http_code'],
				'error' => $session['error'] ?: 'Login failed (HTTP ' . $session['http_code'] . ')',
				'client_count' => null,
			];
		}

		// wg-easy answers 200 with {"status":"TOTP_REQUIRED"} instead of an error
		// code, so the body — not the status — decides whether the session cookie
		// is usable. Without this the button reports a confusing "client list
		// fetch failed" when the service account has 2FA enabled.
		$totpError = $this->totpBlocker((string) $session['body']);
		if ($totpError !== null) {
			return ['ok' => false, 'http_code' => 200, 'error' => $totpError, 'client_count' => null];
		}

		$clients = $this->getWithCookies($url . '/api/client', $session['cookies']);
		if (!$clients['ok']) {
			return [
				'ok' => false,
				'http_code' => $clients['http_code'],
				'error' => $clients['error'] ?: 'Client list fetch failed',
				'client_count' => null,
			];
		}

		$data = json_decode((string) $clients['body'], true);
		$count = is_array($data) ? count($data) : null;

		return [
			'ok' => true,
			'http_code' => 200,
			'error' => '',
			'client_count' => $count,
		];
	}

	/**
	 * Operator-facing message when a 200 login body reports a TOTP challenge.
	 */
	private function totpBlocker(string $body): ?string
	{
		$data = json_decode($body, true);
		$status = is_array($data) ? strtoupper((string) ($data['status'] ?? '')) : '';
		return match ($status) {
			'TOTP_REQUIRED' => 'wg-easy requires a TOTP code for this account — disable 2FA on the Nextcloud service account',
			'INVALID_TOTP_CODE' => 'wg-easy rejected the TOTP code',
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, http_code: int, error: string, cookies: list<string>, body: string|false}
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
			return ['ok' => false, 'http_code' => $httpCode, 'error' => $error, 'cookies' => [], 'body' => false];
		}

		$headerText = substr((string) $response, 0, $headerSize);
		$body = substr((string) $response, $headerSize);
		$cookies = $this->parseSetCookieHeaders($headerText);

		return ['ok' => true, 'http_code' => $httpCode, 'error' => '', 'cookies' => $cookies, 'body' => $body];
	}

	/**
	 * @param list<string> $cookies
	 * @return array{ok: bool, http_code: int, error: string, body: string|false}
	 */
	private function getWithCookies(string $url, array $cookies): array
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
		if ($cookies !== []) {
			curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookies));
		}
		$body = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return [
			'ok' => ($body !== false && $error === '' && $httpCode === 200),
			'http_code' => $httpCode,
			'error' => $error,
			'body' => $body,
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
