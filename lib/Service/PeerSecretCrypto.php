<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCP\Security\ICrypto;
use Throwable;

/**
 * Seals WireGuard private keys and pre-shared keys at rest.
 *
 * Deliberately NOT `SecretCrypto`: that class returns the stored blob verbatim
 * when decryption fails, which is the right call for a wg-easy password (the
 * operator sees a failed login) and the wrong call for key material — a
 * ciphertext handed to a `.conf` builder would produce configs that silently
 * never handshake. Every failure here throws instead.
 */
class PeerSecretCrypto
{
	public const PREFIX = 'enc:peer:v1:';

	/**
	 * Blobs written by `SecretCrypto` before the peer store existed. Accepted on
	 * read so a hand-migrated value keeps working; never written.
	 */
	private const LEGACY_PREFIX = 'enc:v1:';

	public function __construct(
		private ICrypto $crypto,
	) {
	}

	/**
	 * @throws PeerSecretCryptoException when the key is empty or the backend fails
	 */
	public function encrypt(string $plain): string
	{
		if (trim($plain) === '') {
			throw new PeerSecretCryptoException('refusing to store an empty WireGuard key');
		}
		try {
			$sealed = $this->crypto->encrypt($plain);
		} catch (Throwable $e) {
			throw new PeerSecretCryptoException('failed to encrypt WireGuard key material', 0, $e);
		}
		if ($sealed === '') {
			throw new PeerSecretCryptoException('crypto backend returned an empty ciphertext');
		}
		return self::PREFIX . $sealed;
	}

	/**
	 * @throws PeerSecretCryptoException on an unknown envelope, a decrypt failure,
	 *                                   or an empty plaintext
	 */
	public function decrypt(string $stored): string
	{
		$payload = $this->stripPrefix($stored);
		if ($payload === null) {
			throw new PeerSecretCryptoException(
				'stored WireGuard key is not wrapped in a known encryption envelope'
			);
		}
		try {
			$plain = $this->crypto->decrypt($payload);
		} catch (Throwable $e) {
			throw new PeerSecretCryptoException('failed to decrypt stored WireGuard key', 0, $e);
		}
		if ($plain === '') {
			throw new PeerSecretCryptoException('stored WireGuard key decrypted to an empty string');
		}
		return $plain;
	}

	/**
	 * Null-tolerant read for optional secrets (pre-shared keys).
	 *
	 * An absent PSK is legitimate; a present-but-undecryptable one is not.
	 *
	 * @throws PeerSecretCryptoException
	 */
	public function decryptOptional(?string $stored): ?string
	{
		if ($stored === null || $stored === '') {
			return null;
		}
		return $this->decrypt($stored);
	}

	public function isSealed(string $stored): bool
	{
		return $this->stripPrefix($stored) !== null;
	}

	private function stripPrefix(string $stored): ?string
	{
		foreach ([self::PREFIX, self::LEGACY_PREFIX] as $prefix) {
			if (str_starts_with($stored, $prefix)) {
				$payload = substr($stored, strlen($prefix));
				return $payload === '' ? null : $payload;
			}
		}
		return null;
	}
}
