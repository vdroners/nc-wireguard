<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyProbe;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller
{
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private AppSettings $appSettings,
		private NativeHealthService $nativeHealth,
		private WgEasyProbe $wgEasyProbe,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function getSettings(): JSONResponse
	{
		return new JSONResponse([
			'dashboard_enabled' => $this->appSettings->isDashboardEnabled(),
			'wg_easy_admin_url' => $this->appSettings->getWgEasyAdminUrl(),
			'hide_wg_easy_admin_link' => $this->appSettings->isWgEasyAdminLinkHidden(),
			'wg_easy_api_url' => $this->config->getAppValue(
				Application::APP_ID,
				'wg_easy_api_url',
				'',
			),
			'wg_easy_username' => $this->appSettings->getWgEasyUsername(),
			'wg_easy_password_configured' => $this->appSettings->isWgEasyPasswordConfigured(),
			'poll_interval_seconds' => $this->appSettings->getPollIntervalSeconds(),
			'retention_days' => $this->appSettings->getRetentionDays(),
			'geoip_enabled' => $this->appSettings->isGeoIpEnabled(),
			'geoip_provider' => $this->appSettings->getGeoIpProvider(),
			'geoip_api_key_configured' => $this->appSettings->isGeoIpApiKeyConfigured(),
			'geoip_custom_url' => $this->appSettings->getGeoIpCustomUrl(),
			'watchdog_enabled' => $this->appSettings->isWatchdogEnabled(),
			'watchdog_interval_minutes' => $this->appSettings->getWatchdogIntervalMinutes(),
		]);
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function saveSettings(): JSONResponse
	{
		$body = file_get_contents('php://input');
		$data = json_decode((string) $body, true);
		if (!is_array($data)) {
			return new JSONResponse(['error' => 'Invalid JSON'], Http::STATUS_BAD_REQUEST);
		}

		if (array_key_exists('dashboard_enabled', $data)) {
			$this->appSettings->setDashboardEnabled((bool) $data['dashboard_enabled']);
		}
		if (isset($data['wg_easy_admin_url'])) {
			$this->appSettings->setWgEasyAdminUrl((string) $data['wg_easy_admin_url']);
		}
		if (array_key_exists('hide_wg_easy_admin_link', $data)) {
			$this->appSettings->setWgEasyAdminLinkHidden((bool) $data['hide_wg_easy_admin_link']);
		}
		if (isset($data['wg_easy_api_url'])) {
			$this->appSettings->setWgEasyApiUrl((string) $data['wg_easy_api_url']);
		}
		if (isset($data['wg_easy_username'])) {
			$this->appSettings->setWgEasyUsername((string) $data['wg_easy_username']);
		}
		if (isset($data['wg_easy_password']) && (string) $data['wg_easy_password'] !== '') {
			$this->appSettings->setWgEasyPassword((string) $data['wg_easy_password']);
		}
		if (isset($data['poll_interval_seconds'])) {
			$this->appSettings->setPollIntervalSeconds((int) $data['poll_interval_seconds']);
		}
		if (isset($data['retention_days'])) {
			$this->appSettings->setRetentionDays((int) $data['retention_days']);
		}
		if (array_key_exists('geoip_enabled', $data)) {
			$this->appSettings->setGeoIpEnabled((bool) $data['geoip_enabled']);
		}
		if (isset($data['geoip_provider'])) {
			$this->appSettings->setGeoIpProvider((string) $data['geoip_provider']);
		}
		if (isset($data['geoip_api_key']) && (string) $data['geoip_api_key'] !== '') {
			$this->appSettings->setGeoIpApiKey((string) $data['geoip_api_key']);
		}
		if (isset($data['geoip_custom_url'])) {
			$this->appSettings->setGeoIpCustomUrl((string) $data['geoip_custom_url']);
		}
		if (array_key_exists('watchdog_enabled', $data)) {
			$this->appSettings->setWatchdogEnabled((bool) $data['watchdog_enabled']);
		}
		if (isset($data['watchdog_interval_minutes'])) {
			$this->appSettings->setWatchdogIntervalMinutes((int) $data['watchdog_interval_minutes']);
		}

		return $this->getSettings();
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function testConnection(): JSONResponse
	{
		$appVersion = $this->config->getAppValue(Application::APP_ID, 'installed_version', '');
		$health = $this->nativeHealth->getHealth($appVersion);
		$ok = ($health['status'] ?? '') === 'ok';
		if (!$ok) {
			return new JSONResponse([
				'ok' => false,
				'health' => $health,
			], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse([
			'ok' => true,
			'health' => $health,
		]);
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function testWgEasy(): JSONResponse
	{
		$result = $this->wgEasyProbe->testSession();
		if (!$result['ok']) {
			return new JSONResponse([
				'ok' => false,
				'error' => $result['error'],
				'http_code' => $result['http_code'],
			], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse([
			'ok' => true,
			'client_count' => $result['client_count'],
		]);
	}
}
