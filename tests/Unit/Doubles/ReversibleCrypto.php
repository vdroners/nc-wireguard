<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCP\Security\ICrypto;

/**
 * Reversible stand-in for Nextcloud's crypto backend.
 *
 * Not encryption — just enough of a transform that a test can prove the
 * ciphertext is not the plaintext and still round-trip it.
 */
final class ReversibleCrypto implements ICrypto
{
	public function encrypt(string $data): string
	{
		return base64_encode(strrev($data));
	}

	public function decrypt(string $data): string
	{
		return strrev((string) base64_decode($data, true));
	}
}
