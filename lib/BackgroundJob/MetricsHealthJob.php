<?php

declare(strict_types=1);

namespace OCA\NcWireguard\BackgroundJob;

use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Service\AppSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Native metrics heartbeat watchdog — logs stale polls and wg-easy failures.
 */
class MetricsHealthJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private AppSettings $appSettings,
		private MetricsHeartbeatMapper $heartbeatMapper,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		if (!$this->appSettings->isDashboardEnabled()) {
			return;
		}
		if (!$this->appSettings->isWatchdogEnabled()) {
			return;
		}

		$intervalMin = $this->appSettings->getWatchdogIntervalMinutes();
		if ($intervalMin > 0 && $intervalMin !== 5) {
			$this->setInterval(max(60, $intervalMin * 60));
		}

		$heartbeat = $this->heartbeatMapper->findSingleton();
		if ($heartbeat === null) {
			$this->logger->error('nc_wireguard metrics watchdog: no heartbeat row yet');
			return;
		}

		if (!$heartbeat->getSuccess()) {
			$this->logger->error('nc_wireguard metrics watchdog: last poll failed', [
				'error' => $heartbeat->getErrorMessage(),
			]);
		}
		if (!$heartbeat->getWgEasyOk()) {
			$this->logger->warning('nc_wireguard metrics watchdog: wg-easy was unreachable on last poll');
		}

		$lastPoll = $heartbeat->getLastPollAt();
		$ageSec = time() - $lastPoll->getTimestamp();
		$staleThreshold = max(120, $this->appSettings->getPollIntervalSeconds() * 3);
		if ($ageSec > $staleThreshold) {
			$this->logger->error('nc_wireguard metrics watchdog: stale heartbeat', [
				'age_seconds' => $ageSec,
				'threshold' => $staleThreshold,
			]);
		}
	}
}
