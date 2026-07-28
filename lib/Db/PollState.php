<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method bool getConnected()
 * @method void setConnected(bool $connected)
 * @method string|null getEndpoint()
 * @method void setEndpoint(?string $endpoint)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string|null getPeerUuid()
 * @method void setPeerUuid(?string $peerUuid)
 * @method string|null getPublicKey()
 * @method void setPublicKey(?string $publicKey)
 */
class PollState extends Entity
{
	protected $clientId;
	protected $connected;
	protected $endpoint;
	protected $updatedAt;
	/**
	 * Engine-agnostic identity for the FSM row. Populated once the poller keys
	 * on public key instead of the wg-easy integer id (P6).
	 */
	protected $peerUuid;
	protected $publicKey;

	public function __construct()
	{
		$this->addType('clientId', 'integer');
		$this->addType('connected', 'boolean');
		$this->addType('updatedAt', 'datetime');
	}

	public function getId(): ?int
	{
		return $this->clientId;
	}

	public function setId($id): void
	{
		$this->setClientId((int) $id);
	}
}
