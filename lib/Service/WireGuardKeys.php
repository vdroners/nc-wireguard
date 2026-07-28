<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use RuntimeException;

/**
 * Curve25519 keypairs for the native engine.
 *
 * WireGuard keys are plain Curve25519 scalars, so libsodium (bundled with PHP
 * 7.2+) covers this without shelling out to `wg genkey` — which the Nextcloud
 * container does not have installed anyway.
 */
final class WireGuardKeys
{
	/**
	 * @return array{private: string, public: string} base64, as `wg` expects
	 * @throws RuntimeException when libsodium is unavailable
	 */
	public static function generate(): array
	{
		if (!function_exists('sodium_crypto_scalarmult_base')) {
			throw new RuntimeException(
				'libsodium is not available — cannot generate a WireGuard keypair'
			);
		}
		$private = self::clamp(random_bytes(32));
		return [
			'private' => base64_encode($private),
			'public' => base64_encode(sodium_crypto_scalarmult_base($private)),
		];
	}

	/**
	 * @throws RuntimeException
	 */
	public static function publicFromPrivate(string $privateKeyBase64): string
	{
		if (!function_exists('sodium_crypto_scalarmult_base')) {
			throw new RuntimeException('libsodium is not available');
		}
		$raw = base64_decode(trim($privateKeyBase64), true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new RuntimeException('Not a 32-byte base64 WireGuard key');
		}
		return base64_encode(sodium_crypto_scalarmult_base(self::clamp($raw)));
	}

	/**
	 * A key is 32 raw bytes, base64-encoded — 44 characters ending in `=`.
	 */
	public static function isValid(string $key): bool
	{
		$raw = base64_decode(trim($key), true);
		return $raw !== false && strlen($raw) === 32;
	}

	/**
	 * RFC 7748 clamping. libsodium clamps internally on use, but the stored key
	 * has to match what `wg` will report back or every comparison drifts.
	 */
	private static function clamp(string $raw): string
	{
		$raw[0] = chr(ord($raw[0]) & 248);
		$raw[31] = chr((ord($raw[31]) & 127) | 64);
		return $raw;
	}
}
