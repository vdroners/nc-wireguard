<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use RuntimeException;

/**
 * Raised when WireGuard key material cannot be sealed or unsealed.
 *
 * Never carries the key (or the ciphertext) in its message — callers log it.
 */
class PeerSecretCryptoException extends RuntimeException
{
}
