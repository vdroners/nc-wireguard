<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<PeerSecret> */
class PeerSecretMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_peer_secrets', PeerSecret::class);
	}

	public function findByPeerId(int $peerId): ?PeerSecret
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('peer_id', $qb->createNamedParameter($peerId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * `peer_id` is the primary key, so an update cannot go through
	 * QBMapper::update() (it would target `id`).
	 */
	public function save(PeerSecret $secret, bool $exists): void
	{
		if (!$exists) {
			$this->insert($secret);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('private_key_enc', $qb->createNamedParameter(
				$secret->getPrivateKeyEnc(),
				IQueryBuilder::PARAM_STR
			))
			->set('psk_enc', $qb->createNamedParameter($secret->getPskEnc(), IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq(
				'peer_id',
				$qb->createNamedParameter($secret->getPeerId(), IQueryBuilder::PARAM_INT)
			));
		$qb->executeStatement();
	}

	public function deleteByPeerId(int $peerId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('peer_id', $qb->createNamedParameter($peerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
