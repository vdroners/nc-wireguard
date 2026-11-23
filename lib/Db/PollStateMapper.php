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

	/**
	 * Admin health counts only — no endpoints, keys, or peer identities.
	 *
	 * `connected` mirrors a recent handshake (FSM timeout). Peers that are not
	 * connected are reported as "never" for the admin summary because poll_state
	 * does not retain historical handshake timestamps.
	 *
	 * @return array{total: int, with_handshake: int, never: int}
	 */
	public function countHandshakeStats(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('connected')
			->from($this->getTableName());
		$result = $qb->executeQuery();

		$total = 0;
		$withHandshake = 0;
		while ($row = $result->fetchAssociative()) {
			$total++;
			$connected = $row['connected'] ?? false;
			if ($connected === true || $connected === 1 || $connected === '1') {
				$withHandshake++;
			}
		}

		return [
			'total' => $total,
			'with_handshake' => $withHandshake,
			'never' => $total - $withHandshake,
		];
	}
}
