<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCP\IConfig;

/**
 * Host metrics adapter: HostProcCollector with CPU snapshot persisted in appconfig.
 */
class SystemMetricsCollector
{
	public function __construct(
		private HostProcCollector $hostProc,
		private IConfig $config,
	) {
	}

	/**
	 * @return array{cpu_percent: float, mem_percent: float, disk_percent: float, net_rx_bytes: int, net_tx_bytes: int}
	 */
	public function collect(): array
	{
		$previous = $this->loadCpuSnapshot();
		$result = $this->hostProc->collect($previous);
		$this->saveCpuSnapshot($result['cpu_snapshot']);
		unset($result['cpu_snapshot']);
		return $result;
	}

	/** @return array{idle: int, total: int}|null */
	private function loadCpuSnapshot(): ?array
	{
		$idle = $this->config->getAppValue(Application::APP_ID, 'metrics_cpu_prev_idle', '');
		$total = $this->config->getAppValue(Application::APP_ID, 'metrics_cpu_prev_total', '');
		if ($idle === '' || $total === '') {
			return null;
		}
		return ['idle' => (int) $idle, 'total' => (int) $total];
	}

	/** @param array{idle: int, total: int} $snapshot */
	private function saveCpuSnapshot(array $snapshot): void
	{
		$this->config->setAppValue(Application::APP_ID, 'metrics_cpu_prev_idle', (string) $snapshot['idle']);
		$this->config->setAppValue(Application::APP_ID, 'metrics_cpu_prev_total', (string) $snapshot['total']);
	}
}
