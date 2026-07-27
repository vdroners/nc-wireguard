<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCP\IConfig;

/**
 * Remember redeemed OTL tokens so NC enforces single-use even when the
 * upstream /cnf/{token} endpoint is lenient.
 */
class OtlRedeemTracker
{
	private const CONFIG_KEY = 'otl_redeemed_tokens';
	private const MAX_ENTRIES = 200;
	private const TTL_SECONDS = 600;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function wasRedeemed(string $token): bool
	{
		$token = trim($token);
		if ($token === '') {
			return false;
		}
		$now = time();
		$state = $this->loadAndPrune($now);
		return isset($state[$token]);
	}

	public function markRedeemed(string $token): void
	{
		$token = trim($token);
		if ($token === '') {
			return;
		}
		$now = time();
		$state = $this->loadAndPrune($now);
		$state[$token] = $now;
		if (count($state) > self::MAX_ENTRIES) {
			asort($state);
			$state = array_slice($state, -self::MAX_ENTRIES, null, true);
		}
		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($state) ?: '{}'
		);
	}

	/** @return array<string, int> */
	private function loadAndPrune(int $now): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '{}');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $token => $ts) {
			if (!is_string($token) || $token === '' || !is_numeric($ts)) {
				continue;
			}
			if (($now - (int) $ts) > self::TTL_SECONDS) {
				continue;
			}
			$out[$token] = (int) $ts;
		}
		return $out;
	}
}
