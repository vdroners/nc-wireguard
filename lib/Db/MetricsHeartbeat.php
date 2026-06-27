<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \DateTime getLastPollAt()
 * @method void setLastPollAt(\DateTime $lastPollAt)
 * @method bool getSuccess()
 * @method void setSuccess(bool $success)
 * @method bool getWgEasyOk()
 * @method void setWgEasyOk(bool $wgEasyOk)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class MetricsHeartbeat extends Entity
{
	protected $lastPollAt;
	protected $success;
	protected $wgEasyOk;
	protected $errorMessage;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('success', 'boolean');
		$this->addType('wgEasyOk', 'boolean');
		$this->addType('lastPollAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
