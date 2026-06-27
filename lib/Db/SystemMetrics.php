<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \DateTime getTs()
 * @method void setTs(\DateTime $ts)
 * @method float getCpuPercent()
 * @method void setCpuPercent(float $cpuPercent)
 * @method float getMemPercent()
 * @method void setMemPercent(float $memPercent)
 * @method float getDiskPercent()
 * @method void setDiskPercent(float $diskPercent)
 * @method int getNetRxBytes()
 * @method void setNetRxBytes(int $netRxBytes)
 * @method int getNetTxBytes()
 * @method void setNetTxBytes(int $netTxBytes)
 */
class SystemMetrics extends Entity
{
	protected $ts;
	protected $cpuPercent;
	protected $memPercent;
	protected $diskPercent;
	protected $netRxBytes;
	protected $netTxBytes;

	public function __construct()
	{
		$this->addType('cpuPercent', 'float');
		$this->addType('memPercent', 'float');
		$this->addType('diskPercent', 'float');
		$this->addType('netRxBytes', 'integer');
		$this->addType('netTxBytes', 'integer');
		$this->addType('ts', 'datetime');
	}
}
