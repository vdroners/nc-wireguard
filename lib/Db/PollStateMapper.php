<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<PollState> */
class PollStateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_poll_state', PollState::class);
	}

	public function findByClientId(int $clientId): ?PollState
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function saveState(PollState $state, bool $exists): void
	{
		if (!$exists) {
			$this->insert($state);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('connected', $qb->createNamedParameter($state->getConnected(), IQueryBuilder::PARAM_BOOL))
			->set('endpoint', $qb->createNamedParameter($state->getEndpoint(), IQueryBuilder::PARAM_STR))
			->set('updated_at', $qb->createNamedParameter($state->getUpdatedAt(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq(
				'client_id',
				$qb->createNamedParameter($state->getClientId(), IQueryBuilder::PARAM_INT)
			));
		$qb->executeStatement();
	}
}
