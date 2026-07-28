<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Peer> */
class PeerMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_peers', Peer::class);
	}

	public function findByPublicKey(string $publicKey): ?Peer
	{
		return $this->findOneBy('public_key', $publicKey, IQueryBuilder::PARAM_STR);
	}

	public function findByUuid(string $uuid): ?Peer
	{
		return $this->findOneBy('uuid', $uuid, IQueryBuilder::PARAM_STR);
	}

	public function findByWgEasyId(int $wgEasyId): ?Peer
	{
		return $this->findOneBy('wg_easy_id', $wgEasyId, IQueryBuilder::PARAM_INT);
	}

	public function findById(int $id): ?Peer
	{
		return $this->findOneBy('id', $id, IQueryBuilder::PARAM_INT);
	}

	/**
	 * Resolve the integer id a controller received into a stored peer.
	 *
	 * The engine interface is keyed by `int $peerId`, but which integer that is
	 * depends on who is answering: wg-easy hands out its own ids, while the
	 * native engine uses the `nc_wg_peers` row id. Trying the wg-easy id first
	 * keeps links minted before cutover working afterwards.
	 */
	public function findByEngineId(int $id): ?Peer
	{
		return $this->findByWgEasyId($id) ?? $this->findById($id);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'peer_count'))
			->from($this->getTableName());
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? (int) array_values($row)[0] : 0;
	}

	/**
	 * @return list<Peer>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Every assigned tunnel address, as stored (may carry a `/32` suffix).
	 *
	 * @return list<string>
	 */
	public function findAssignedIpv4(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('ipv4')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('ipv4'));
		$result = $qb->executeQuery();
		$taken = [];
		while (($row = $result->fetch()) !== false) {
			$value = is_array($row) ? ($row['ipv4'] ?? null) : null;
			if (is_string($value) && $value !== '') {
				$taken[] = $value;
			}
		}
		$result->closeCursor();
		return $taken;
	}

	private function findOneBy(string $column, mixed $value, int $type): ?Peer
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
