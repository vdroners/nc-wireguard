<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Sealed key material for one peer, split from `nc_wg_peers` so peer reads
 * (dashboard, lists, exports) never pull ciphertext into memory.
 *
 * Both columns hold `PeerSecretCrypto` envelopes, never raw keys.
 *
 * @method int getPeerId()
 * @method void setPeerId(int $peerId)
 * @method string getPrivateKeyEnc()
 * @method void setPrivateKeyEnc(string $privateKeyEnc)
 * @method string|null getPskEnc()
 * @method void setPskEnc(?string $pskEnc)
 */
class PeerSecret extends Entity
{
	protected $peerId;
	protected $privateKeyEnc;
	protected $pskEnc;

	public function __construct()
	{
		$this->addType('peerId', 'integer');
	}

	public function getId(): ?int
	{
		return $this->peerId === null ? null : (int) $this->peerId;
	}

	public function setId($id): void
	{
		$this->setPeerId((int) $id);
	}
}
