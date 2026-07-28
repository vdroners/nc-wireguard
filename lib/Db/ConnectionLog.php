<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \DateTime getTs()
 * @method void setTs(\DateTime $ts)
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getEvent()
 * @method void setEvent(string $event)
 * @method string|null getEndpoint()
 * @method void setEndpoint(?string $endpoint)
 * @method string|null getPeerUuid()
 * @method void setPeerUuid(?string $peerUuid)
 */
class ConnectionLog extends Entity
{
	protected $ts;
	protected $clientId;
	protected $name;
	protected $event;
	protected $endpoint;
	/** Backfilled at cutover so history survives losing the wg-easy integer id. */
	protected $peerUuid;

	public function __construct()
	{
		$this->addType('clientId', 'integer');
		$this->addType('ts', 'datetime');
	}
}
