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
 */
class PollState extends Entity
{
	protected $clientId;
	protected $connected;
	protected $endpoint;
	protected $updatedAt;

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
