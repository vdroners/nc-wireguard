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
 * @method int getTransferRx()
 * @method void setTransferRx(int $transferRx)
 * @method int getTransferTx()
 * @method void setTransferTx(int $transferTx)
 * @method string|null getPeerUuid()
 * @method void setPeerUuid(?string $peerUuid)
 */
class BandwidthLog extends Entity
{
	protected $ts;
	protected $clientId;
	protected $name;
	protected $transferRx;
	protected $transferTx;
	/** Backfilled at cutover so history survives losing the wg-easy integer id. */
	protected $peerUuid;

	public function __construct()
	{
		$this->addType('clientId', 'integer');
		$this->addType('transferRx', 'integer');
		$this->addType('transferTx', 'integer');
		$this->addType('ts', 'datetime');
	}
}
