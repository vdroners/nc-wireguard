<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MetricsHeartbeat> */
class MetricsHeartbeatMapper extends QBMapper
{
	public const SINGLETON_ID = 1;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_metrics_heartbeat', MetricsHeartbeat::class);
	}

	public function findSingleton(): ?MetricsHeartbeat
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter(self::SINGLETON_ID, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function recordPoll(\DateTime $lastPollAt, bool $success, bool $wgEasyOk, ?string $errorMessage): void
	{
		$existing = $this->findSingleton();
		$row = $existing ?? new MetricsHeartbeat();
		if ($existing === null) {
			$row->setId(self::SINGLETON_ID);
		}
		$row->setLastPollAt($lastPollAt);
		$row->setSuccess($success);
		$row->setWgEasyOk($wgEasyOk);
		$row->setErrorMessage($errorMessage);
		$row->setUpdatedAt($lastPollAt);
		if ($existing === null) {
			$this->insert($row);
		} else {
			$this->update($row);
		}
	}
}
