<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Db\MetricsHeartbeatMapper;

/**
 * Native backend health for /api/status (mirrors sidecar /api/health semantics).
 */
class NativeHealthService
{
	public function __construct(
		private MetricsHeartbeatMapper $heartbeatMapper,
		private WgEasyClient $wgEasyClient,
		private AppSettings $settings,
		private HostProcCollector $hostProc,
	) {
	}

	/**
	 * @return array{
	 *   status: string,
	 *   version: string,
	 *   wg_easy: bool,
	 *   poller: bool,
	 *   host_metrics: bool,
	 *   last_poll_at: string|null,
	 *   error_message: string|null
	 * }
	 */
	public function getHealth(string $appVersion): array
	{
		$heartbeat = $this->heartbeatMapper->findSingleton();
		$pollInterval = $this->settings->getPollIntervalSeconds();
		$staleThreshold = max(60, $pollInterval * 2);

		$pollerOk = false;
		$wgEasyOk = false;
		$lastPollAt = null;
		$errorMessage = null;

		if ($heartbeat !== null) {
			$lastPollAt = $heartbeat->getLastPollAt();
			$age = time() - $lastPollAt->getTimestamp();
			$pollerOk = $heartbeat->getSuccess() && $age <= $staleThreshold;
			$wgEasyOk = $heartbeat->getWgEasyOk();
			$errorMessage = $heartbeat->getErrorMessage();
		}

		if (!$wgEasyOk) {
			$clients = $this->wgEasyClient->getClients();
			$wgEasyOk = $clients !== null;
		}

		$hostMetricsOk = $this->hostProc->isAvailable();
		$status = ($pollerOk && $wgEasyOk) ? 'ok' : 'degraded';

		return [
			'status' => $status,
			'version' => $appVersion,
			'wg_easy' => $wgEasyOk,
			'poller' => $pollerOk,
			'host_metrics' => $hostMetricsOk,
			'last_poll_at' => $lastPollAt !== null ? $lastPollAt->format('c') : null,
			'error_message' => $errorMessage,
		];
	}
}
