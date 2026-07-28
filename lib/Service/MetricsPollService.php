<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\BandwidthLog;
use OCA\NcWireguard\Db\BandwidthLogMapper;
use OCA\NcWireguard\Db\ConnectionLog;
use OCA\NcWireguard\Db\ConnectionLogMapper;
use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Db\PollState;
use OCA\NcWireguard\Db\PollStateMapper;
use OCA\NcWireguard\Db\SystemMetrics;
use OCA\NcWireguard\Db\SystemMetricsMapper;
use OCA\NcWireguard\Util\EndpointIp;
use Psr\Log\LoggerInterface;

/**
 * Single poll cycle: engine peers → bandwidth + connection FSM + system metrics + heartbeat.
 *
 * Metrics rows stay keyed by the engine's integer peer id; the public-key
 * identity exposed by `getRuntimeStats()` lands in a later schema change.
 */
class MetricsPollService
{
	public function __construct(
		private WireGuardEngineInterface $engine,
		private ConnectionStateMachine $fsm,
		private GeoIpService $geoIp,
		private SystemMetricsCollector $systemMetrics,
		private BandwidthLogMapper $bandwidthMapper,
		private ConnectionLogMapper $connectionMapper,
		private SystemMetricsMapper $systemMetricsMapper,
		private PollStateMapper $pollStateMapper,
		private MetricsHeartbeatMapper $heartbeatMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{ok: bool, clients: int, bandwidth_rows: int, connection_events: int, error: string}
	 */
	public function poll(): array
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$nowDt = \DateTime::createFromImmutable($now);

		$clients = $this->engine->listPeers();
		if ($clients === null) {
			$this->heartbeatMapper->recordPoll($nowDt, false, false, 'wg-easy unreachable');
			return [
				'ok' => false,
				'clients' => 0,
				'bandwidth_rows' => 0,
				'connection_events' => 0,
				'error' => 'wg-easy unreachable',
			];
		}

		$bandwidthRows = 0;
		$connectionEvents = 0;

		foreach ($clients as $client) {
			if (!is_array($client) || !isset($client['id'], $client['name'])) {
				continue;
			}
			$clientId = (int) $client['id'];
			$name = (string) $client['name'];
			$rx = (int) ($client['transferRx'] ?? 0);
			$tx = (int) ($client['transferTx'] ?? 0);
			$connected = $this->fsm->isConnected($client, $now);

			$bw = new BandwidthLog();
			$bw->setTs($nowDt);
			$bw->setClientId($clientId);
			$bw->setName($name);
			$bw->setTransferRx($rx);
			$bw->setTransferTx($tx);
			$this->bandwidthMapper->insert($bw);
			$bandwidthRows++;

			$previous = $this->pollStateMapper->findByClientId($clientId);
			foreach ($this->fsm->transitionEvents($client, $previous, $connected) as $event) {
				$conn = new ConnectionLog();
				$conn->setTs($nowDt);
				$conn->setClientId($clientId);
				$conn->setName($name);
				$conn->setEvent($event['event']);
				$conn->setEndpoint($event['endpoint']);
				$this->connectionMapper->insert($conn);
				$connectionEvents++;

				if ($event['event'] === 'connected' && $event['endpoint'] !== null) {
					$ip = EndpointIp::parse($event['endpoint']);
					if ($ip !== null) {
						$this->geoIp->resolve($ip);
					}
				}
			}

			$state = $previous ?? new PollState();
			$state->setClientId($clientId);
			$state->setConnected($connected);
			$endpoint = isset($client['endpoint']) && is_string($client['endpoint'])
				? $client['endpoint']
				: null;
			$state->setEndpoint($endpoint);
			$state->setUpdatedAt($nowDt);
			if ($previous === null) {
				$this->pollStateMapper->insert($state);
			} else {
				$this->pollStateMapper->saveState($state, true);
			}
		}

		$sys = $this->systemMetrics->collect();
		$metrics = new SystemMetrics();
		$metrics->setTs($nowDt);
		$metrics->setCpuPercent($sys['cpu_percent']);
		$metrics->setMemPercent($sys['mem_percent']);
		$metrics->setDiskPercent($sys['disk_percent']);
		$metrics->setNetRxBytes($sys['net_rx_bytes']);
		$metrics->setNetTxBytes($sys['net_tx_bytes']);
		$this->systemMetricsMapper->insert($metrics);

		$this->heartbeatMapper->recordPoll($nowDt, true, true, null);
		$this->logger->debug('nc_wireguard: poll cycle complete', [
			'clients' => count($clients),
			'bandwidth_rows' => $bandwidthRows,
			'connection_events' => $connectionEvents,
		]);

		return [
			'ok' => true,
			'clients' => count($clients),
			'bandwidth_rows' => $bandwidthRows,
			'connection_events' => $connectionEvents,
			'error' => '',
		];
	}
}
