<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCP\IConfig;

/**
 * Simple per-IP window rate limit for the public OTL redeem route.
 *
 * Stored in appconfig so it survives across PHP-FPM workers without Redis.
 * Not a hard security boundary — just slows drive-by token guessing.
 */
class OtlRedeemRateLimiter
{
	public const LIMIT = 20;
	public const WINDOW_SECONDS = 60;
	private const CONFIG_KEY = 'otl_redeem_rate';

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return array{allowed: bool, remaining: int, retry_after: int}
	 */
	public function attempt(string $clientIp): array
	{
		$now = time();
		$key = $this->bucketKey($clientIp);
		$state = $this->load();
		$bucket = $state[$key] ?? null;

		if (!is_array($bucket)
			|| !isset($bucket['window_start'], $bucket['count'])
			|| ($now - (int) $bucket['window_start']) >= self::WINDOW_SECONDS
		) {
			$state[$key] = ['window_start' => $now, 'count' => 1];
			$this->pruneAndSave($state, $now);
			return [
				'allowed' => true,
				'remaining' => self::LIMIT - 1,
				'retry_after' => 0,
			];
		}

		$count = (int) $bucket['count'];
		$windowStart = (int) $bucket['window_start'];
		if ($count >= self::LIMIT) {
			$retry = max(1, self::WINDOW_SECONDS - ($now - $windowStart));
			return [
				'allowed' => false,
				'remaining' => 0,
				'retry_after' => $retry,
			];
		}

		$state[$key]['count'] = $count + 1;
		$this->pruneAndSave($state, $now);
		return [
			'allowed' => true,
			'remaining' => self::LIMIT - ($count + 1),
			'retry_after' => 0,
		];
	}

	private function bucketKey(string $clientIp): string
	{
		$ip = trim($clientIp);
		if ($ip === '') {
			$ip = 'unknown';
		}
		return hash('sha256', $ip);
	}

	/** @return array<string, array{window_start: int, count: int}> */
	private function load(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '{}');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function pruneAndSave(array $state, int $now): void
	{
		foreach ($state as $key => $bucket) {
			if (!is_array($bucket) || !isset($bucket['window_start'])) {
				unset($state[$key]);
				continue;
			}
			if (($now - (int) $bucket['window_start']) >= self::WINDOW_SECONDS * 2) {
				unset($state[$key]);
			}
		}
		// Cap map size so a noisy attacker cannot grow appconfig forever.
		if (count($state) > 500) {
			uasort($state, static function ($a, $b): int {
				$sa = is_array($a) ? (int) ($a['window_start'] ?? 0) : 0;
				$sb = is_array($b) ? (int) ($b['window_start'] ?? 0) : 0;
				return $sb <=> $sa;
			});
			$state = array_slice($state, 0, 500, true);
		}
		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($state) ?: '{}'
		);
	}
}
