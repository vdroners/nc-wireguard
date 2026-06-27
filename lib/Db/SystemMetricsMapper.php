<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use DateTimeImmutable;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<SystemMetrics> */
class SystemMetricsMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_system_metrics', SystemMetrics::class);
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
	 * @return list<SystemMetrics>
	 */
	public function findSinceHours(int $hours): array
	{
		$cutoff = new DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC'));
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte(
				'ts',
				$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
			))
			->orderBy('ts', 'ASC');
		return $this->findEntities($qb);
	}
}
