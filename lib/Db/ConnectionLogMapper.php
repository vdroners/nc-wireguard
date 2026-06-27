<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use DateTimeImmutable;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ConnectionLog> */
class ConnectionLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_connection_log', ConnectionLog::class);
	}

	public function deleteOlderThan(DateTimeImmutable $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt(
				'ts',
				$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
			));
		return $qb->executeStatement();
	}

	/**
	 * @return list<ConnectionLog>
	 */
	public function findSinceDays(int $days, ?int $clientId = null): array
	{
		$cutoff = new DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC'));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte(
				'ts',
				$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
			))
			->orderBy('ts', 'DESC');
		if ($clientId !== null) {
			$qb->andWhere($qb->expr()->eq(
				'client_id',
				$qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)
			));
		}
		return $this->findEntities($qb);
	}
}
