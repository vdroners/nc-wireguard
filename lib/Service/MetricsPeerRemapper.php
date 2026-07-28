<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Db\PeerMapper;
use OCP\IDBConnection;

/**
 * Re-key historical metrics from wg-easy ids onto stable peer identities (P6).
 *
 * The metrics tables were written when `client_id` — a wg-easy row id — was the
 * only handle we had. That id does not survive the cutover: the native engine
 * numbers peers by `nc_wg_peers.id`, and a rebuilt wg-easy would renumber
 * anyway. Left alone, every chart would break at the cutover timestamp, and
 * worse, a *reused* id would silently graft one peer's history onto another.
 *
 * So before switching engines, backfill the columns that do survive:
 * `peer_uuid` everywhere, plus `public_key` on the poll state. Both are already
 * in the schema (see `SchemaRegistry`) and both are stable across engines,
 * rebuilds, and renames.
 *
 * `client_id` is deliberately left as-is. It is the audit trail of what wg-easy
 * called the peer, it costs nothing to keep, and rewriting it would make the
 * operation non-idempotent — a second run could no longer tell which rows it
 * had already handled.
 */
class MetricsPeerRemapper
{
	/**
	 * Tables keyed by wg-easy `client_id`, and the identity columns to fill.
	 *
	 * @var array<string, list<string>>
	 */
	private const TARGETS = [
		'nc_wg_bandwidth_log' => ['peer_uuid'],
		'nc_wg_connection_log' => ['peer_uuid'],
		'nc_wg_poll_state' => ['peer_uuid', 'public_key'],
	];

	public function __construct(
		private IDBConnection $db,
		private PeerMapper $peers,
	) {
	}

	/**
	 * @return array{
	 *     mapped: int,
	 *     unmapped: list<int>,
	 *     tables: array<string, array{matched: int, updated: int, orphaned: int}>,
	 *     dry_run: bool
	 * }
	 */
	public function run(bool $dryRun = true): array
	{
		$byWgEasyId = [];
		foreach ($this->peers->findAll() as $peer) {
			$wgEasyId = $peer->getWgEasyId();
			if ($wgEasyId !== null) {
				$byWgEasyId[(int) $wgEasyId] = [
					'uuid' => (string) $peer->getUuid(),
					'public_key' => (string) $peer->getPublicKey(),
				];
			}
		}

		$tables = [];
		$unmapped = [];
		foreach (self::TARGETS as $table => $columns) {
			$tables[$table] = $this->remapTable($table, $columns, $byWgEasyId, $dryRun, $unmapped);
		}

		$unmapped = array_values(array_unique($unmapped));
		sort($unmapped);

		return [
			'mapped' => count($byWgEasyId),
			'unmapped' => $unmapped,
			'tables' => $tables,
			'dry_run' => $dryRun,
		];
	}

	/**
	 * @param list<string> $columns
	 * @param array<int, array{uuid: string, public_key: string}> $byWgEasyId
	 * @param list<int> $unmapped collects client ids with no peer, by reference
	 * @return array{matched: int, updated: int, orphaned: int}
	 */
	private function remapTable(
		string $table,
		array $columns,
		array $byWgEasyId,
		bool $dryRun,
		array &$unmapped,
	): array {
		$matched = 0;
		$updated = 0;
		$orphaned = 0;

		foreach ($this->pendingClientIds($table, $columns) as $clientId => $rowCount) {
			$identity = $byWgEasyId[$clientId] ?? null;
			if ($identity === null) {
				// A peer that existed in wg-easy but was never imported — deleted
				// before the import, most likely. Its history stays queryable by
				// client_id; it just will not follow a peer forward.
				$orphaned += $rowCount;
				$unmapped[] = $clientId;
				continue;
			}
			$matched += $rowCount;
			if ($dryRun) {
				continue;
			}
			$updated += $this->fill($table, $columns, $clientId, $identity);
		}

		return ['matched' => $matched, 'updated' => $updated, 'orphaned' => $orphaned];
	}

	/**
	 * Client ids with at least one row still missing an identity column.
	 *
	 * @param list<string> $columns
	 * @return array<int, int> client_id => rows needing a backfill
	 */
	private function pendingClientIds(string $table, array $columns): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('client_id')
			->selectAlias($qb->func()->count('*'), 'row_count')
			->from($table)
			->groupBy('client_id');

		$missing = $qb->expr()->orX();
		foreach ($columns as $column) {
			$missing->add($qb->expr()->isNull($column));
			$missing->add($qb->expr()->eq($column, $qb->createNamedParameter('')));
		}
		$qb->where($missing);

		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int) $row['client_id']] = (int) $row['row_count'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @param list<string> $columns
	 * @param array{uuid: string, public_key: string} $identity
	 */
	private function fill(string $table, array $columns, int $clientId, array $identity): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($columns as $column) {
			$value = $column === 'public_key' ? $identity['public_key'] : $identity['uuid'];
			$qb->set($column, $qb->createNamedParameter($value));
		}

		// Only touch rows that are actually blank, so a re-run after a partial
		// failure cannot overwrite an identity an operator has already corrected.
		$missing = $qb->expr()->orX();
		foreach ($columns as $column) {
			$missing->add($qb->expr()->isNull($column));
			$missing->add($qb->expr()->eq($column, $qb->createNamedParameter('')));
		}
		$qb->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->andWhere($missing);

		return (int) $qb->executeStatement();
	}
}
