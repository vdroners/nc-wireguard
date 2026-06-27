<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Read-only host metrics via bind-mounted /host/proc (see docs/HOST_METRICS_AUDIT.md).
 *
 * @return array{cpu_percent: float, mem_percent: float, disk_percent: float, net_rx_bytes: int, net_tx_bytes: int, cpu_snapshot: array{idle: int, total: int}}
 */
class HostProcCollector
{
	public function __construct(
		private string $procRoot = '/host/proc',
	) {
	}

	public function resolveProcRoot(): string
	{
		if (is_readable($this->procRoot . '/stat') && is_readable($this->procRoot . '/meminfo')) {
			return rtrim($this->procRoot, '/');
		}
		return '/proc';
	}

	public function isAvailable(): bool
	{
		$root = $this->resolveProcRoot();
		return is_readable($root . '/stat') && is_readable($root . '/meminfo');
	}

	public function readBootTimeIso(): ?string
	{
		$root = $this->resolveProcRoot();
		$lines = @file($root . '/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return null;
		}
		foreach ($lines as $line) {
			if (!str_starts_with($line, 'btime ')) {
				continue;
			}
			$ts = (int) trim(substr($line, 6));
			if ($ts <= 0) {
				return null;
			}
			return (new \DateTimeImmutable('@' . $ts))
				->setTimezone(new \DateTimeZone('UTC'))
				->format('Y-m-d\TH:i:sP');
		}
		return null;
	}

	/**
	 * @param array{idle: int, total: int}|null $previousSnapshot
	 * @return array{cpu_percent: float, mem_percent: float, disk_percent: float, net_rx_bytes: int, net_tx_bytes: int, cpu_snapshot: array{idle: int, total: int}}
	 */
	public function collect(?array $previousSnapshot = null): array
	{
		$root = $this->resolveProcRoot();
		$snapshot = $this->readCpuSnapshot($root . '/stat');
		$cpuPercent = 0.0;
		if ($previousSnapshot !== null && $snapshot['total'] > $previousSnapshot['total']) {
			$deltaTotal = $snapshot['total'] - $previousSnapshot['total'];
			$deltaIdle = $snapshot['idle'] - $previousSnapshot['idle'];
			$cpuPercent = max(0.0, min(100.0, (1.0 - ($deltaIdle / $deltaTotal)) * 100.0));
		}

		[$rx, $tx] = $this->readNetworkBytes($root . '/net/dev');
		return [
			'cpu_percent' => round($cpuPercent, 2),
			'mem_percent' => round($this->readMemoryPercent($root . '/meminfo'), 2),
			'disk_percent' => round($this->readDiskPercent(), 2),
			'net_rx_bytes' => $rx,
			'net_tx_bytes' => $tx,
			'cpu_snapshot' => $snapshot,
		];
	}

	/** @return array{idle: int, total: int} */
	private function readCpuSnapshot(string $statPath): array
	{
		$lines = @file($statPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines) || !isset($lines[0])) {
			return ['idle' => 0, 'total' => 0];
		}
		$parts = preg_split('/\s+/', trim($lines[0]));
		// Aggregate line is "cpu" (Linux /proc/stat); not "cpu:".
		if (!is_array($parts) || ($parts[0] ?? '') !== 'cpu' || count($parts) < 5) {
			return ['idle' => 0, 'total' => 0];
		}
		array_shift($parts);
		$values = array_map('intval', $parts);
		$idle = ($values[3] ?? 0) + ($values[4] ?? 0);
		$total = array_sum($values);
		return ['idle' => $idle, 'total' => $total];
	}

	private function readMemoryPercent(string $meminfoPath): float
	{
		$lines = @file($meminfoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return 0.0;
		}
		$memTotal = 0;
		$memAvailable = 0;
		foreach ($lines as $line) {
			if (str_starts_with($line, 'MemTotal:')) {
				$memTotal = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
			} elseif (str_starts_with($line, 'MemAvailable:')) {
				$memAvailable = (int) filter_var($line, FILTER_SANITIZE_NUMBER_INT);
			}
		}
		if ($memTotal <= 0) {
			return 0.0;
		}
		if ($memAvailable <= 0) {
			return 100.0;
		}
		return (1.0 - ($memAvailable / $memTotal)) * 100.0;
	}

	private function readDiskPercent(): float
	{
		$total = @disk_total_space('/');
		$free = @disk_free_space('/');
		if ($total === false || $free === false || $total <= 0) {
			return 0.0;
		}
		return (1.0 - ($free / $total)) * 100.0;
	}

	/** @return array{0: int, 1: int} */
	private function readNetworkBytes(string $netDevPath): array
	{
		$lines = @file($netDevPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return [0, 0];
		}
		$rx = 0;
		$tx = 0;
		foreach ($lines as $idx => $line) {
			if ($idx < 2) {
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$iface = trim(substr($line, 0, $colon));
			if ($iface === 'lo') {
				continue;
			}
			$fields = preg_split('/\s+/', trim(substr($line, $colon + 1)));
			if (!is_array($fields) || count($fields) < 9) {
				continue;
			}
			$rx += (int) $fields[0];
			$tx += (int) $fields[8];
		}
		return [$rx, $tx];
	}
}
