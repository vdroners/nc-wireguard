<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\BandwidthLogMapper;
use OCA\NcWireguard\Db\ConnectionLogMapper;
use OCA\NcWireguard\Db\GeoIpCacheMapper;
use OCA\NcWireguard\Db\SystemMetricsMapper;
use Psr\Log\LoggerInterface;

/**
 * Delete metrics rows older than configured retention (default 30 days).
 */
class MetricsPruneService
{
	public function __construct(
		private AppSettings $settings,
		private BandwidthLogMapper $bandwidthMapper,
		private ConnectionLogMapper $connectionMapper,
		private SystemMetricsMapper $systemMetricsMapper,
		private GeoIpCacheMapper $geoIpMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{bandwidth: int, connections: int, system: int, geoip: int}
	 */
	public function prune(): array
	{
		$days = $this->settings->getRetentionDays();
		$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->modify('-' . $days . ' days');

		$counts = [
			'bandwidth' => $this->bandwidthMapper->deleteOlderThan($cutoff),
			'connections' => $this->connectionMapper->deleteOlderThan($cutoff),
			'system' => $this->systemMetricsMapper->deleteOlderThan($cutoff),
			'geoip' => $this->geoIpMapper->deleteQueriedBefore($cutoff),
		];

		$this->logger->info('nc_wireguard: pruned metrics older than ' . $days . ' days', $counts);
		return $counts;
	}
}
