<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCA\NcWireguard\Db\PeerSecret;
use OCA\NcWireguard\Db\PeerSecretMapper;
use OCA\NcWireguard\Tests\Stubs\NullDbConnection;

/** Peer-secret mapper backed by an array, keyed by peer id. */
final class InMemoryPeerSecretMapper extends PeerSecretMapper
{
	/** @var array<int, PeerSecret> */
	private array $rows = [];

	public int $saveCalls = 0;

	public function __construct()
	{
		parent::__construct(new NullDbConnection());
	}

	public function findByPeerId(int $peerId): ?PeerSecret
	{
		return $this->rows[$peerId] ?? null;
	}

	public function save(PeerSecret $secret, bool $exists): void
	{
		$this->saveCalls++;
		$this->rows[$secret->getPeerId()] = $secret;
	}

	public function deleteByPeerId(int $peerId): void
	{
		unset($this->rows[$peerId]);
	}
}
