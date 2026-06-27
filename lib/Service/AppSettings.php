<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\IConfig;

/**
 * Typed accessors for nc_wireguard appconfig keys.
 */
class AppSettings
{
	public function __construct(
		private IConfig $config,
		private SecretCrypto $secrets,
		private DockerUrlResolver $urlResolver,
	) {
	}

	public function isDashboardEnabled(): bool
	{
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_enabled',
			'1'
		));
		return $raw === '1' || strtolower($raw) === 'true';
	}

	public function setDashboardEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'dashboard_enabled', $enabled ? '1' : '0');
	}

	public function getWgEasyApiUrl(): string
	{
		$url = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'wg_easy_api_url',
			'http://wg-easy:51821'
		));
		return $url !== '' ? $this->urlResolver->resolveHostDockerInternal($url) : 'http://wg-easy:51821';
	}

	public function setWgEasyApiUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_easy_api_url', trim($url));
	}

	public function getWgEasyUsername(): string
	{
		return (string) $this->config->getAppValue(Application::APP_ID, 'wg_easy_username', 'admin');
	}

	public function setWgEasyUsername(string $username): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_easy_username', trim($username));
	}

	public function getWgEasyPassword(): string
	{
		return $this->secrets->get('wg_easy_password');
	}

	public function setWgEasyPassword(string $password): void
	{
		$this->secrets->set('wg_easy_password', $password);
	}

	public function isWgEasyPasswordConfigured(): bool
	{
		return $this->secrets->isConfigured('wg_easy_password');
	}

	public function getPollIntervalSeconds(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'poll_interval_seconds', '30');
		return max(10, min(300, $raw));
	}

	public function setPollIntervalSeconds(int $seconds): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'poll_interval_seconds',
			(string) max(10, min(300, $seconds))
		);
	}

	public function getRetentionDays(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'retention_days', '30');
		return max(1, min(365, $raw));
	}

	public function setRetentionDays(int $days): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'retention_days',
			(string) max(1, min(365, $days))
		);
	}

	public function isGeoIpEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'geoip_enabled', '1') === '1';
	}

	public function setGeoIpEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'geoip_enabled', $enabled ? '1' : '0');
	}

	public function getWatchdogIntervalMinutes(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'watchdog_interval_minutes', '5');
		return max(1, min(60, $raw));
	}

	public function setWatchdogIntervalMinutes(int $minutes): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'watchdog_interval_minutes',
			(string) max(1, min(60, $minutes))
		);
	}

	public function isWatchdogEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'watchdog_enabled', '1') === '1';
	}

	public function setWatchdogEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'watchdog_enabled', $enabled ? '1' : '0');
	}
}
