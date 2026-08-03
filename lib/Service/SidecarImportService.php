<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\BandwidthLog;
use OCA\NcWireguard\Db\BandwidthLogMapper;
use OCA\NcWireguard\Db\ConnectionLog;
use OCA\NcWireguard\Db\ConnectionLogMapper;
use OCA\NcWireguard\Db\GeoIpCache;
use OCA\NcWireguard\Db\GeoIpCacheMapper;
use OCA\NcWireguard\Db\PollState;
use OCA\NcWireguard\Db\PollStateMapper;
use OCA\NcWireguard\Db\SystemMetrics;
use OCA\NcWireguard\Db\SystemMetricsMapper;
use OCP\IDBConnection;
use PDO;
use RuntimeException;

/**
 * One-way import from wg-dashboard sidecar SQLite into NC native metrics tables.
 */
class SidecarImportService
{
	/** Empty by design — operators must pass an explicit path (no lab host default). */
	public const DEFAULT_SQLITE_PATH = '';

	public function __construct(
		private IDBConnection $db,
		private BandwidthLogMapper $bandwidthMapper,
		private ConnectionLogMapper $connectionMapper,
		private GeoIpCacheMapper $geoIpMapper,
		private SystemMetricsMapper $systemMetricsMapper,
		private PollStateMapper $pollStateMapper,
	) {
	}

	/**
	 * @return array{
	 *   ok: bool,
	 *   sqlite_path: string,
	 *   inserted: array<string, int>,
	 *   skipped: array<string, int>,
	 *   source_counts: array<string, int>,
	 *   nc_counts_after: array<string, int>
	 * }
	 */
	public function import(string $sqlitePath): array
	{
		$sqlite = $this->openSqlite($sqlitePath);
		$inserted = [
			'bandwidth_log' => 0,
			'connection_log' => 0,
			'geoip_cache' => 0,
			'system_metrics' => 0,
			'poll_state' => 0,
		];
		$skipped = [
			'bandwidth_log' => 0,
			'connection_log' => 0,
			'geoip_cache' => 0,
			'system_metrics' => 0,
			'poll_state' => 0,
		];

		$existingBw = $this->loadBandwidthKeys();
		$stmt = $sqlite->query(
			'SELECT ts, client_id, name, transfer_rx, transfer_tx FROM bandwidth_log ORDER BY id'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$key = $this->bandwidthKey(
				(string) $row['ts'],
				(int) $row['client_id'],
				(int) $row['transfer_rx'],
				(int) $row['transfer_tx']
			);
			if (isset($existingBw[$key])) {
				$skipped['bandwidth_log']++;
				continue;
			}
			$entity = new BandwidthLog();
			$entity->setTs($this->parseTs((string) $row['ts']));
			$entity->setClientId((int) $row['client_id']);
			$entity->setName((string) $row['name']);
			$entity->setTransferRx((int) $row['transfer_rx']);
			$entity->setTransferTx((int) $row['transfer_tx']);
			$this->bandwidthMapper->insert($entity);
			$existingBw[$key] = true;
			$inserted['bandwidth_log']++;
		}

		$existingConn = $this->loadConnectionKeys();
		$stmt = $sqlite->query(
			'SELECT ts, client_id, name, event, endpoint FROM connection_log ORDER BY id'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$key = $this->connectionKey(
				(string) $row['ts'],
				(int) $row['client_id'],
				(string) $row['event'],
				$row['endpoint'] !== null ? (string) $row['endpoint'] : null
			);
			if (isset($existingConn[$key])) {
				$skipped['connection_log']++;
				continue;
			}
			$entity = new ConnectionLog();
			$entity->setTs($this->parseTs((string) $row['ts']));
			$entity->setClientId((int) $row['client_id']);
			$entity->setName((string) $row['name']);
			$entity->setEvent((string) $row['event']);
			$entity->setEndpoint($row['endpoint'] !== null ? (string) $row['endpoint'] : null);
			$this->connectionMapper->insert($entity);
			$existingConn[$key] = true;
			$inserted['connection_log']++;
		}

		$stmt = $sqlite->query(
			'SELECT ip, country, country_code, city, region, lat, lon, isp, queried_at FROM geoip_cache'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$entity = new GeoIpCache();
			$entity->setIp((string) $row['ip']);
			$entity->setCountry($row['country'] !== null ? (string) $row['country'] : null);
			$entity->setCountryCode($row['country_code'] !== null ? (string) $row['country_code'] : null);
			$entity->setCity($row['city'] !== null ? (string) $row['city'] : null);
			$entity->setRegion($row['region'] !== null ? (string) $row['region'] : null);
			$entity->setLat($row['lat'] !== null ? (float) $row['lat'] : null);
			$entity->setLon($row['lon'] !== null ? (float) $row['lon'] : null);
			$entity->setIsp($row['isp'] !== null ? (string) $row['isp'] : null);
			$entity->setQueriedAt($this->parseTs((string) $row['queried_at']));
			$existing = $this->geoIpMapper->findByIp($entity->getIp());
			if ($existing !== null) {
				$skipped['geoip_cache']++;
			} else {
				$inserted['geoip_cache']++;
			}
			$this->geoIpMapper->upsert($entity);
		}

		$existingSys = $this->loadSystemMetricsKeys();
		$stmt = $sqlite->query(
			'SELECT ts, cpu_percent, mem_percent, disk_percent, net_rx_bytes, net_tx_bytes FROM system_metrics ORDER BY id'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$key = $this->systemMetricsKey(
				(string) $row['ts'],
				(float) $row['cpu_percent'],
				(float) $row['mem_percent'],
				(float) $row['disk_percent'],
				(int) $row['net_rx_bytes'],
				(int) $row['net_tx_bytes']
			);
			if (isset($existingSys[$key])) {
				$skipped['system_metrics']++;
				continue;
			}
			$entity = new SystemMetrics();
			$entity->setTs($this->parseTs((string) $row['ts']));
			$entity->setCpuPercent((float) $row['cpu_percent']);
			$entity->setMemPercent((float) $row['mem_percent']);
			$entity->setDiskPercent((float) $row['disk_percent']);
			$entity->setNetRxBytes((int) $row['net_rx_bytes']);
			$entity->setNetTxBytes((int) $row['net_tx_bytes']);
			$this->systemMetricsMapper->insert($entity);
			$existingSys[$key] = true;
			$inserted['system_metrics']++;
		}

		$pollRows = $this->loadPollStateSource($sqlite);
		foreach ($pollRows as $pollRow) {
			$clientId = (int) $pollRow['client_id'];
			$existing = $this->pollStateMapper->findByClientId($clientId);
			$entity = new PollState();
			$entity->setClientId($clientId);
			$entity->setConnected((bool) $pollRow['connected']);
			$entity->setEndpoint($pollRow['endpoint'] !== null ? (string) $pollRow['endpoint'] : null);
			$entity->setUpdatedAt($this->parseTs((string) $pollRow['updated_at']));
			if ($existing !== null
				&& $existing->getConnected() === $entity->getConnected()
				&& $existing->getEndpoint() === $entity->getEndpoint()
			) {
				$skipped['poll_state']++;
			} else {
				$inserted['poll_state']++;
			}
			$this->pollStateMapper->saveState($entity, $existing !== null);
		}

		$sourceCounts = $this->countSource($sqlite);
		$ncCounts = $this->countNc();

		return [
			'ok' => true,
			'sqlite_path' => $sqlitePath,
			'inserted' => $inserted,
			'skipped' => $skipped,
			'source_counts' => $sourceCounts,
			'nc_counts_after' => $ncCounts,
		];
	}

	/**
	 * @return array{
	 *   ok: bool,
	 *   sqlite_path: string,
	 *   tables: array<string, array{source: int, nc: int, missing: int}>,
	 *   poll_state: array{expected: int, nc: int, key_mismatches: list<string>},
	 *   errors: list<string>
	 * }
	 */
	public function verify(string $sqlitePath): array
	{
		$sqlite = $this->openSqlite($sqlitePath);
		$errors = [];
		$tables = [];

		$ncBw = $this->loadBandwidthKeys();
		$missingBw = 0;
		$sourceBw = 0;
		$stmt = $sqlite->query(
			'SELECT ts, client_id, transfer_rx, transfer_tx FROM bandwidth_log'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$sourceBw++;
			$key = $this->bandwidthKey(
				(string) $row['ts'],
				(int) $row['client_id'],
				(int) $row['transfer_rx'],
				(int) $row['transfer_tx']
			);
			if (!isset($ncBw[$key])) {
				$missingBw++;
			}
		}
		$tables['bandwidth_log'] = [
			'source' => $sourceBw,
			'nc' => $this->countTable('nc_wg_bandwidth_log'),
			'missing' => $missingBw,
		];
		if ($missingBw > 0) {
			$errors[] = "bandwidth_log: {$missingBw} source rows missing from NC";
		}

		$ncConn = $this->loadConnectionKeys();
		$missingConn = 0;
		$sourceConn = 0;
		$stmt = $sqlite->query(
			'SELECT ts, client_id, event, endpoint FROM connection_log'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$sourceConn++;
			$key = $this->connectionKey(
				(string) $row['ts'],
				(int) $row['client_id'],
				(string) $row['event'],
				$row['endpoint'] !== null ? (string) $row['endpoint'] : null
			);
			if (!isset($ncConn[$key])) {
				$missingConn++;
			}
		}
		$tables['connection_log'] = [
			'source' => $sourceConn,
			'nc' => $this->countTable('nc_wg_connection_log'),
			'missing' => $missingConn,
		];
		if ($missingConn > 0) {
			$errors[] = "connection_log: {$missingConn} source rows missing from NC";
		}

		$missingGeo = 0;
		$sourceGeo = 0;
		$stmt = $sqlite->query('SELECT ip FROM geoip_cache');
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$sourceGeo++;
			if ($this->geoIpMapper->findByIp((string) $row['ip']) === null) {
				$missingGeo++;
			}
		}
		$tables['geoip_cache'] = [
			'source' => $sourceGeo,
			'nc' => $this->countTable('nc_wg_geoip_cache'),
			'missing' => $missingGeo,
		];
		if ($missingGeo > 0) {
			$errors[] = "geoip_cache: {$missingGeo} source IPs missing from NC";
		}

		$ncSys = $this->loadSystemMetricsKeys();
		$missingSys = 0;
		$sourceSys = 0;
		$stmt = $sqlite->query(
			'SELECT ts, cpu_percent, mem_percent, disk_percent, net_rx_bytes, net_tx_bytes FROM system_metrics'
		);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$sourceSys++;
			$key = $this->systemMetricsKey(
				(string) $row['ts'],
				(float) $row['cpu_percent'],
				(float) $row['mem_percent'],
				(float) $row['disk_percent'],
				(int) $row['net_rx_bytes'],
				(int) $row['net_tx_bytes']
			);
			if (!isset($ncSys[$key])) {
				$missingSys++;
			}
		}
		$tables['system_metrics'] = [
			'source' => $sourceSys,
			'nc' => $this->countTable('nc_wg_system_metrics'),
			'missing' => $missingSys,
		];
		if ($missingSys > 0) {
			$errors[] = "system_metrics: {$missingSys} source rows missing from NC";
		}

		$pollSource = $this->loadPollStateSource($sqlite);
		$keyMismatches = [];
		foreach ($pollSource as $expected) {
			$clientId = (int) $expected['client_id'];
			$ncRow = $this->pollStateMapper->findByClientId($clientId);
			if ($ncRow === null) {
				$keyMismatches[] = "client_id {$clientId}: missing in NC";
				continue;
			}
			if ($ncRow->getConnected() !== (bool) $expected['connected']) {
				$keyMismatches[] = "client_id {$clientId}: connected mismatch";
			}
			$expEp = $expected['endpoint'] !== null ? (string) $expected['endpoint'] : null;
			if ($ncRow->getEndpoint() !== $expEp) {
				$keyMismatches[] = "client_id {$clientId}: endpoint mismatch";
			}
		}
		if ($keyMismatches !== []) {
			$errors[] = 'poll_state: ' . implode('; ', array_slice($keyMismatches, 0, 5));
		}

		$pollState = [
			'expected' => count($pollSource),
			'nc' => $this->countTable('nc_wg_poll_state'),
			'key_mismatches' => $keyMismatches,
		];

		return [
			'ok' => $errors === [],
			'sqlite_path' => $sqlitePath,
			'tables' => $tables,
			'poll_state' => $pollState,
			'errors' => $errors,
		];
	}

	private function openSqlite(string $sqlitePath): PDO
	{
		if (!is_readable($sqlitePath)) {
			throw new RuntimeException('Sidecar SQLite not readable: ' . $sqlitePath);
		}
		$pdo = new PDO('sqlite:' . $sqlitePath);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $pdo;
	}

	private function parseTs(string $ts): DateTime
	{
		$immutable = new DateTimeImmutable(str_replace('Z', '+00:00', $ts), new DateTimeZone('UTC'));
		$dt = DateTime::createFromImmutable($immutable);
		if ($dt === false) {
			throw new RuntimeException('Invalid timestamp: ' . $ts);
		}
		return $dt;
	}

	private function bandwidthKey(string $ts, int $clientId, int $rx, int $tx): string
	{
		return $this->normalizeTsKey($ts) . '|' . $clientId . '|' . $rx . '|' . $tx;
	}

	private function connectionKey(string $ts, int $clientId, string $event, ?string $endpoint): string
	{
		return $this->normalizeTsKey($ts) . '|' . $clientId . '|' . $event . '|' . ($endpoint ?? '');
	}

	private function systemMetricsKey(
		string $ts,
		float $cpu,
		float $mem,
		float $disk,
		int $netRx,
		int $netTx,
	): string {
		return $this->normalizeTsKey($ts) . '|' . $cpu . '|' . $mem . '|' . $disk . '|' . $netRx . '|' . $netTx;
	}

	private function normalizeTsKey(string $ts): string
	{
		$immutable = new DateTimeImmutable(str_replace('Z', '+00:00', $ts), new DateTimeZone('UTC'));
		return $immutable->format('Y-m-d H:i:s');
	}

	/**
	 * Sidecar keeps poll_state in memory; import from SQLite table when present,
	 * otherwise derive latest connection event per client.
	 *
	 * @return list<array{client_id: int, connected: bool, endpoint: string|null, updated_at: string}>
	 */
	private function loadPollStateSource(PDO $sqlite): array
	{
		if ($this->sqliteHasTable($sqlite, 'poll_state')) {
			$rows = [];
			$stmt = $sqlite->query(
				'SELECT client_id, connected, endpoint, updated_at FROM poll_state'
			);
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$rows[] = [
					'client_id' => (int) $row['client_id'],
					'connected' => (bool) $row['connected'],
					'endpoint' => $row['endpoint'] !== null ? (string) $row['endpoint'] : null,
					'updated_at' => (string) $row['updated_at'],
				];
			}
			return $rows;
		}

		$stmt = $sqlite->query(
			'SELECT c.client_id, c.event, c.endpoint, c.ts
			 FROM connection_log c
			 INNER JOIN (
			   SELECT client_id, MAX(ts) AS max_ts
			   FROM connection_log
			   GROUP BY client_id
			 ) latest ON c.client_id = latest.client_id AND c.ts = latest.max_ts'
		);
		$rows = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$rows[] = [
				'client_id' => (int) $row['client_id'],
				'connected' => (string) $row['event'] === 'connected',
				'endpoint' => (string) $row['event'] === 'connected' && $row['endpoint'] !== null
					? (string) $row['endpoint']
					: null,
				'updated_at' => (string) $row['ts'],
			];
		}
		return $rows;
	}

	private function sqliteHasTable(PDO $sqlite, string $table): bool
	{
		$stmt = $sqlite->prepare(
			"SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
		);
		$stmt->execute([$table]);
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * @return array<string, int>
	 */
	private function countSource(PDO $sqlite): array
	{
		$pollExpected = count($this->loadPollStateSource($sqlite));
		return [
			'bandwidth_log' => (int) $sqlite->query('SELECT COUNT(*) FROM bandwidth_log')->fetchColumn(),
			'connection_log' => (int) $sqlite->query('SELECT COUNT(*) FROM connection_log')->fetchColumn(),
			'geoip_cache' => (int) $sqlite->query('SELECT COUNT(*) FROM geoip_cache')->fetchColumn(),
			'system_metrics' => (int) $sqlite->query('SELECT COUNT(*) FROM system_metrics')->fetchColumn(),
			'poll_state' => $pollExpected,
		];
	}

	/**
	 * @return array<string, int>
	 */
	public function countNc(): array
	{
		return [
			'bandwidth_log' => $this->countTable('nc_wg_bandwidth_log'),
			'connection_log' => $this->countTable('nc_wg_connection_log'),
			'geoip_cache' => $this->countTable('nc_wg_geoip_cache'),
			'system_metrics' => $this->countTable('nc_wg_system_metrics'),
			'poll_state' => $this->countTable('nc_wg_poll_state'),
		];
	}

	private function countTable(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from($table);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) ($row['cnt'] ?? 0);
	}

	/**
	 * @return array<string, true>
	 */
	private function loadBandwidthKeys(): array
	{
		$keys = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('ts', 'client_id', 'transfer_rx', 'transfer_tx')
			->from('nc_wg_bandwidth_log');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ts = $row['ts'] instanceof DateTime
				? $row['ts']->format('Y-m-d H:i:s')
				: $this->normalizeTsKey((string) $row['ts']);
			$keys[$this->bandwidthKey(
				$ts,
				(int) $row['client_id'],
				(int) $row['transfer_rx'],
				(int) $row['transfer_tx']
			)] = true;
		}
		$result->closeCursor();
		return $keys;
	}

	/**
	 * @return array<string, true>
	 */
	private function loadConnectionKeys(): array
	{
		$keys = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('ts', 'client_id', 'event', 'endpoint')
			->from('nc_wg_connection_log');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ts = $row['ts'] instanceof DateTime
				? $row['ts']->format('Y-m-d H:i:s')
				: $this->normalizeTsKey((string) $row['ts']);
			$keys[$this->connectionKey(
				$ts,
				(int) $row['client_id'],
				(string) $row['event'],
				$row['endpoint'] !== null ? (string) $row['endpoint'] : null
			)] = true;
		}
		$result->closeCursor();
		return $keys;
	}

	/**
	 * @return array<string, true>
	 */
	private function loadSystemMetricsKeys(): array
	{
		$keys = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('ts', 'cpu_percent', 'mem_percent', 'disk_percent', 'net_rx_bytes', 'net_tx_bytes')
			->from('nc_wg_system_metrics');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ts = $row['ts'] instanceof DateTime
				? $row['ts']->format('Y-m-d H:i:s')
				: $this->normalizeTsKey((string) $row['ts']);
			$keys[$this->systemMetricsKey(
				$ts,
				(float) $row['cpu_percent'],
				(float) $row['mem_percent'],
				(float) $row['disk_percent'],
				(int) $row['net_rx_bytes'],
				(int) $row['net_tx_bytes']
			)] = true;
		}
		$result->closeCursor();
		return $keys;
	}
}
