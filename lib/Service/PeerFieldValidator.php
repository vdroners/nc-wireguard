<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Validate and normalize peer form input before it reaches wg-easy.
 *
 * wg-easy validates again server-side, but its zod errors are localized and
 * shaped for its own UI. Validating here gives the operator a field-level
 * message and keeps malformed CIDR/DNS strings out of the audit log.
 *
 * Output keys use wg-easy's camelCase field names (allowedIps, persistentKeepalive)
 * so the result can be handed straight to WgEasyClient.
 */
class PeerFieldValidator
{
	public const NAME_MAX_LENGTH = 128;
	public const MTU_MIN = 1024;
	public const MTU_MAX = 9000;
	public const KEEPALIVE_MIN = 0;
	public const KEEPALIVE_MAX = 65535;
	public const MAX_LIST_ENTRIES = 32;

	/**
	 * @param array<string, mixed> $input Decoded request body
	 * @param bool $requireName True for create; updates may omit fields entirely
	 * @return array{fields: array<string, mixed>, errors: array<string, string>}
	 */
	public function validate(array $input, bool $requireName): array
	{
		$fields = [];
		$errors = [];

		if ($requireName || array_key_exists('name', $input)) {
			$name = $this->normalizeName($input['name'] ?? null);
			if ($name === null) {
				$errors['name'] = 'Name is required and must be 1–' . self::NAME_MAX_LENGTH
					. ' characters without control characters.';
			} else {
				$fields['name'] = $name;
			}
		}

		if (array_key_exists('expiresAt', $input)) {
			$raw = $input['expiresAt'];
			if ($raw === null || $raw === '') {
				$fields['expiresAt'] = null;
			} else {
				$expiresAt = $this->normalizeExpiresAt($raw);
				if ($expiresAt === null) {
					$errors['expiresAt'] = 'Expiry must be an ISO-8601 date (YYYY-MM-DD) or date-time.';
				} else {
					$fields['expiresAt'] = $expiresAt;
				}
			}
		}

		if (array_key_exists('allowedIps', $input)) {
			$raw = $input['allowedIps'];
			if ($raw === null || $raw === '' || $raw === []) {
				$fields['allowedIps'] = null;
			} else {
				$list = $this->splitList($raw);
				$invalid = array_values(array_filter($list, fn (string $e): bool => !$this->isCidr($e)));
				if ($list === [] || count($list) > self::MAX_LIST_ENTRIES) {
					$errors['allowedIps'] = 'AllowedIPs must contain 1–' . self::MAX_LIST_ENTRIES . ' entries.';
				} elseif ($invalid !== []) {
					$errors['allowedIps'] = 'Not valid CIDR notation: ' . implode(', ', $invalid);
				} else {
					$fields['allowedIps'] = $list;
				}
			}
		}

		if (array_key_exists('dns', $input)) {
			$raw = $input['dns'];
			if ($raw === null || $raw === '' || $raw === []) {
				$fields['dns'] = null;
			} else {
				$list = $this->splitList($raw);
				$invalid = array_values(array_filter($list, fn (string $e): bool => !$this->isIpAddress($e)));
				if (count($list) > self::MAX_LIST_ENTRIES) {
					$errors['dns'] = 'At most ' . self::MAX_LIST_ENTRIES . ' DNS servers.';
				} elseif ($invalid !== []) {
					$errors['dns'] = 'Not valid IP addresses: ' . implode(', ', $invalid);
				} else {
					$fields['dns'] = $list;
				}
			}
		}

		if (array_key_exists('mtu', $input) && $input['mtu'] !== null && $input['mtu'] !== '') {
			$mtu = $this->normalizeInt($input['mtu'], self::MTU_MIN, self::MTU_MAX);
			if ($mtu === null) {
				$errors['mtu'] = 'MTU must be an integer between ' . self::MTU_MIN . ' and ' . self::MTU_MAX . '.';
			} else {
				$fields['mtu'] = $mtu;
			}
		}

		if (
			array_key_exists('persistentKeepalive', $input)
			&& $input['persistentKeepalive'] !== null
			&& $input['persistentKeepalive'] !== ''
		) {
			$keepalive = $this->normalizeInt(
				$input['persistentKeepalive'],
				self::KEEPALIVE_MIN,
				self::KEEPALIVE_MAX
			);
			if ($keepalive === null) {
				$errors['persistentKeepalive'] = 'Keepalive must be an integer between '
					. self::KEEPALIVE_MIN . ' and ' . self::KEEPALIVE_MAX . ' seconds.';
			} else {
				$fields['persistentKeepalive'] = $keepalive;
			}
		}

		if (array_key_exists('ipv4Address', $input)) {
			$raw = $input['ipv4Address'];
			if ($raw === null || $raw === '') {
				// Empty means leave existing / let engine assign — omit from fields on create.
				// On update, empty is rejected so we never wipe the assignment accidentally.
				if (!$requireName) {
					$errors['ipv4Address'] = 'IPv4 address cannot be cleared; omit the field to leave it unchanged.';
				}
			} else {
				$ip = trim((string) $raw);
				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
					$errors['ipv4Address'] = 'Must be a valid IPv4 address (e.g. 10.8.0.10).';
				} else {
					$fields['ipv4Address'] = $ip;
				}
			}
		}

		if (array_key_exists('serverEndpoint', $input)) {
			$raw = $input['serverEndpoint'];
			if ($raw === null || $raw === '') {
				$fields['serverEndpoint'] = null;
			} else {
				$endpoint = $this->normalizeServerEndpoint($raw);
				if ($endpoint === null) {
					$errors['serverEndpoint'] = 'Endpoint must be host:port (IPv4/hostname) or [IPv6]:port.';
				} else {
					$fields['serverEndpoint'] = $endpoint;
				}
			}
		}

		return ['fields' => $fields, 'errors' => $errors];
	}

	/**
	 * Accept host:port, IPv4:port, or [IPv6]:port.
	 */
	private function normalizeServerEndpoint(mixed $raw): ?string
	{
		if (!is_string($raw)) {
			return null;
		}
		$value = trim($raw);
		if ($value === '' || mb_strlen($value) > 253) {
			return null;
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			return null;
		}
		// [ipv6]:port
		if (preg_match('/^\[([^\]]+)\]:(\d{1,5})$/', $value, $m) === 1) {
			$port = (int) $m[2];
			if ($port < 1 || $port > 65535) {
				return null;
			}
			if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
				return null;
			}
			return '[' . $m[1] . ']:' . $port;
		}
		// host:port or ipv4:port (last colon separates port)
		if (preg_match('/^(.+):(\d{1,5})$/', $value, $m) !== 1) {
			return null;
		}
		$host = $m[1];
		$port = (int) $m[2];
		if ($port < 1 || $port > 65535) {
			return null;
		}
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return $host . ':' . $port;
		}
		// Hostname labels (no spaces); allow dots and hyphens.
		if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host) !== 1) {
			return null;
		}
		return $host . ':' . $port;
	}

	private function normalizeName(mixed $raw): ?string
	{
		if (!is_string($raw)) {
			return null;
		}
		$name = trim($raw);
		if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
			return null;
		}
		// wg-easy pipes names into a WireGuard config comment; control characters
		// there would corrupt the emitted .conf.
		if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
			return null;
		}
		return $name;
	}

	/**
	 * Accept `YYYY-MM-DD` or a full ISO-8601 date-time; emit UTC ISO-8601.
	 */
	private function normalizeExpiresAt(mixed $raw): ?string
	{
		if (!is_string($raw)) {
			return null;
		}
		$value = trim($raw);
		if ($value === '' || mb_strlen($value) > 40) {
			return null;
		}
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
	}

	private function normalizeInt(mixed $raw, int $min, int $max): ?int
	{
		if (is_bool($raw) || (!is_int($raw) && !is_string($raw) && !is_float($raw))) {
			return null;
		}
		if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) !== 1) {
			return null;
		}
		if (is_float($raw) && $raw !== floor($raw)) {
			return null;
		}
		$value = (int) $raw;
		return ($value < $min || $value > $max) ? null : $value;
	}

	/**
	 * Accept either a JSON array or a comma/whitespace-separated string.
	 *
	 * @return list<string>
	 */
	private function splitList(mixed $raw): array
	{
		$parts = is_array($raw)
			? $raw
			: (preg_split('/[\s,]+/', (string) $raw) ?: []);
		$out = [];
		foreach ($parts as $part) {
			if (!is_string($part) && !is_numeric($part)) {
				continue;
			}
			$trimmed = trim((string) $part);
			if ($trimmed !== '') {
				$out[] = $trimmed;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * `address/prefix` where prefix fits the address family. A bare IP is
	 * accepted and treated as a host route, matching wg-easy's own leniency.
	 */
	private function isCidr(string $entry): bool
	{
		if (!str_contains($entry, '/')) {
			return $this->isIpAddress($entry);
		}
		[$address, $prefix] = explode('/', $entry, 2);
		if (preg_match('/^\d{1,3}$/', $prefix) !== 1) {
			return false;
		}
		$bits = (int) $prefix;
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return $bits <= 32;
		}
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			return $bits <= 128;
		}
		return false;
	}

	private function isIpAddress(string $entry): bool
	{
		return filter_var($entry, FILTER_VALIDATE_IP) !== false;
	}
}
