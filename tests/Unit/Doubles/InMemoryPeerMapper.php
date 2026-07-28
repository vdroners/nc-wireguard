<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Tests\Stubs\NullDbConnection;
use OCP\AppFramework\Db\Entity;

/** Peer mapper backed by an array — exercises the store without a database. */
final class InMemoryPeerMapper extends PeerMapper
{
	/** @var array<int, Peer> */
	private array $rows = [];

	private int $nextId = 1;

	public function __construct()
	{
		parent::__construct(new NullDbConnection());
	}

	public function findByPublicKey(string $publicKey): ?Peer
	{
		foreach ($this->rows as $row) {
			if ($row->getPublicKey() === $publicKey) {
				return $row;
			}
		}
		return null;
	}

	public function findByUuid(string $uuid): ?Peer
	{
		foreach ($this->rows as $row) {
			if ($row->getUuid() === $uuid) {
				return $row;
			}
		}
		return null;
	}

	public function findByWgEasyId(int $wgEasyId): ?Peer
	{
		foreach ($this->rows as $row) {
			if ($row->getWgEasyId() !== null && (int) $row->getWgEasyId() === $wgEasyId) {
				return $row;
			}
		}
		return null;
	}

	public function findById(int $id): ?Peer
	{
		return $this->rows[$id] ?? null;
	}

	public function countAll(): int
	{
		return count($this->rows);
	}

	/** @return list<Peer> */
	public function findAll(): array
	{
		return array_values($this->rows);
	}

	/** @return list<string> */
	public function findAssignedIpv4(): array
	{
		$taken = [];
		foreach ($this->rows as $row) {
			$ipv4 = $row->getIpv4();
			if (is_string($ipv4) && $ipv4 !== '') {
				$taken[] = $ipv4;
			}
		}
		return $taken;
	}

	public function insert(Entity $entity): Entity
	{
		$id = $this->nextId++;
		$entity->setId($id);
		$this->rows[$id] = $entity;
		return $entity;
	}

	public function update(Entity $entity): Entity
	{
		$this->rows[(int) $entity->getId()] = $entity;
		return $entity;
	}
}
