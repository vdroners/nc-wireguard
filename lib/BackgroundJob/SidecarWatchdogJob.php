<?php

declare(strict_types=1);

namespace OCA\NcWireguard\BackgroundJob;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\DashboardHttpClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class SidecarWatchdogJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private DashboardHttpClient $httpClient,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$enabled = $this->config->getAppValue(Application::APP_ID, 'watchdog_enabled', '1') === '1';
		if (!$enabled || !$this->httpClient->isEnabled()) {
			return;
		}

		$intervalMin = (int) $this->config->getAppValue(
			Application::APP_ID,
			'watchdog_interval_minutes',
			'5'
		);
		if ($intervalMin > 0 && $intervalMin !== 5) {
			$this->setInterval(max(60, $intervalMin * 60));
		}

		$result = $this->httpClient->get('/api/health');
		if (!$result['ok']) {
			$this->logger->error('nc_wireguard watchdog: sidecar unreachable', [
				'error' => $result['error'],
			]);
			return;
		}
		$data = json_decode((string) $result['body'], true);
		if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
			$this->logger->error('nc_wireguard watchdog: sidecar unhealthy', [
				'health' => $data,
			]);
		}
		if (is_array($data) && empty($data['wg_easy'])) {
			$this->logger->warning('nc_wireguard watchdog: wg-easy not reachable from sidecar');
		}
	}
}
