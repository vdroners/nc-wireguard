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
	 * `connected` is a recent handshake inside the FSM window (~180s), not
	 * "ever handshook". poll_state does not store lifetime handshake history,
	 * so `never` is always null (unknown).
	 *
	 * @return array{total: int, connected: int, not_connected: int, never: null}
	 */
	public function countHandshakeStats(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('connected')
			->from($this->getTableName());
		$result = $qb->executeQuery();

		$flags = [];
		while ($row = $result->fetchAssociative()) {
			$connected = $row['connected'] ?? false;
			$flags[] = ($connected === true || $connected === 1 || $connected === '1');
		}

		return self::aggregateHandshakeFlags($flags);
	}

	/**
	 * Pure aggregation for unit tests — disconnected ≠ never-handshaked.
	 *
	 * @param list<bool> $connectedFlags
	 * @return array{total: int, connected: int, not_connected: int, never: null}
	 */
	public static function aggregateHandshakeFlags(array $connectedFlags): array
	{
		$total = count($connectedFlags);
		$connected = 0;
		foreach ($connectedFlags as $flag) {
			if ($flag) {
				$connected++;
			}
		}
		return [
			'total' => $total,
			'connected' => $connected,
			'not_connected' => $total - $connected,
			'never' => null,
		];
	}
}
