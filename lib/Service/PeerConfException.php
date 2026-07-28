<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use RuntimeException;

/**
 * A `.conf` could not be built. Always thrown rather than returning a partial
 * config: a peer file that is missing its key or endpoint looks valid to the
 * operator and simply never handshakes.
 */
class PeerConfException extends RuntimeException
{
}
