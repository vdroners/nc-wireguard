<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\BandwidthLogMapper;
use OCA\NcWireguard\Db\ConnectionLogMapper;
use OCA\NcWireguard\Db\GeoIpCacheMapper;
use OCA\NcWireguard\Db\SystemMetricsMapper;
use OCA\NcWireguard\Util\EndpointIp;

/**
 * Native dashboard read API (mirrors wg-dashboard sidecar routes from NC DB + wg-easy).
 */
class NativeDashboardService
{
	public function __construct(
		private WgEasyClient $wgEasyClient,
		private ConnectionStateMachine $fsm,
		private BandwidthLogMapper $bandwidthMapper,
		private ConnectionLogMapper $connectionMapper,
		private GeoIpCacheMapper $geoIpMapper,
		private SystemMetricsMapper $systemMetricsMapper,
		private SystemMetricsCollector $systemMetricsCollector,
		private HostProcCollector $hostProc,
	) {
	}

	/**
	 * @return array<string, mixed>|array{error: string}
	 */
	public function buildSummary(): array
	{
		$clients = $this->wgEasyClient->getClients();
		if ($clients === null) {
			return ['error' => 'Cannot reach wg-easy'];
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$connected = 0;
		$totalRx = 0;
		$totalTx = 0;
		$clientList = [];

		foreach ($clients as $c) {
			$isConnected = $this->fsm->isConnected($c, $now);
			if ($isConnected) {
				$connected++;
			}
			$rx = (int) ($c['transferRx'] ?? 0);
			$tx = (int) ($c['transferTx'] ?? 0);
			$totalRx += $rx;
			$totalTx += $tx;
			$clientList[] = [
				'id' => (int) $c['id'],
				'name' => (string) ($c['name'] ?? ''),
				'ipv4Address' => (string) ($c['ipv4Address'] ?? ''),
				'connected' => $isConnected,
				'endpoint' => isset($c['endpoint']) ? $c['endpoint'] : null,
				'latestHandshakeAt' => isset($c['latestHandshakeAt']) ? $c['latestHandshakeAt'] : null,
				'transferRx' => $rx,
				'transferTx' => $tx,
				'enabled' => (bool) ($c['enabled'] ?? false),
				'expiresAt' => $c['expiresAt'] ?? null,
			];
		}

		$gauges = $this->systemMetricsCollector->collect();
		$bootTime = $this->hostProc->readBootTimeIso();

		return [
			'clients' => $clientList,
			'connectedCount' => $connected,
			'totalClients' => count($clients),
			'totalRx' => $totalRx,
			'totalTx' => $totalTx,
			'serverBootTime' => $bootTime ?? (new DateTimeImmutable('@0', new DateTimeZone('UTC')))->format('c'),
			'cpu' => $gauges['cpu_percent'],
			'mem' => $gauges['mem_percent'],
			'disk' => $gauges['disk_percent'],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchBandwidth(int $hours, ?int $clientId): array
	{
		$hours = max(1, min(720, $hours));
		if ($clientId !== null && $clientId < 1) {
			$clientId = null;
		}
		$rows = $this->bandwidthMapper->findSinceHours($hours, $clientId);
		$result = [];
		foreach ($rows as $row) {
			$result[] = [
				'ts' => $this->formatTs($row->getTs()),
				'client_id' => $row->getClientId(),
				'name' => $row->getName(),
				'transfer_rx' => $row->getTransferRx(),
				'transfer_tx' => $row->getTransferTx(),
			];
		}
		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchConnections(int $days, ?int $clientId): array
	{
		$days = max(1, min(365, $days));
		if ($clientId !== null && $clientId < 1) {
			$clientId = null;
		}
		$rows = $this->connectionMapper->findSinceDays($days, $clientId);
		$result = [];
		foreach ($rows as $row) {
			$entry = [
				'ts' => $this->formatTs($row->getTs()),
				'client_id' => $row->getClientId(),
				'name' => $row->getName(),
				'event' => $row->getEvent(),
				'endpoint' => $row->getEndpoint(),
			];
			$endpoint = $row->getEndpoint();
			if ($endpoint !== null && $endpoint !== '') {
				$ip = EndpointIp::parse($endpoint);
				if ($ip !== null) {
					$geo = $this->geoIpMapper->findGeoSummaryByIp($ip);
					if ($geo !== null) {
						$entry['geo'] = $geo;
					}
				}
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchGeoip(): array
	{
		$rows = $this->geoIpMapper->findAllOrdered();
		$result = [];
		foreach ($rows as $row) {
			$result[] = [
				'ip' => $row->getIp(),
				'country' => $row->getCountry(),
				'country_code' => $row->getCountryCode(),
				'city' => $row->getCity(),
				'region' => $row->getRegion(),
				'lat' => $row->getLat(),
				'lon' => $row->getLon(),
				'isp' => $row->getIsp(),
				'queried_at' => $this->formatTs($row->getQueriedAt()),
			];
		}
		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchSystem(int $hours): array
	{
		$hours = max(1, min(720, $hours));
		$rows = $this->systemMetricsMapper->findSinceHours($hours);
		$result = [];
		foreach ($rows as $row) {
			$result[] = [
				'ts' => $this->formatTs($row->getTs()),
				'cpu_percent' => $row->getCpuPercent(),
				'mem_percent' => $row->getMemPercent(),
				'disk_percent' => $row->getDiskPercent(),
				'net_rx_bytes' => $row->getNetRxBytes(),
				'net_tx_bytes' => $row->getNetTxBytes(),
			];
		}
		return $result;
	}

	private function formatTs(\DateTimeInterface $dt): string
	{
		$utc = DateTimeImmutable::createFromInterface($dt)->setTimezone(new DateTimeZone('UTC'));
		$formatted = $utc->format('Y-m-d\TH:i:s');
		$micro = $utc->format('u');
		if ($micro !== '000000') {
			$formatted .= '.' . rtrim($micro, '0');
		}
		return $formatted . $utc->format('P');
	}
}
