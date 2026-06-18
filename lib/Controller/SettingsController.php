<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\DashboardHttpClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller
{
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private DashboardHttpClient $httpClient,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function requireAdmin(): ?JSONResponse
	{
		$user = $this->userSession->getUser();
		if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		return null;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getSettings(): JSONResponse
	{
		if ($deny = $this->requireAdmin()) {
			return $deny;
		}
		return new JSONResponse([
			'dashboard_internal_url' => $this->config->getAppValue(
				Application::APP_ID,
				'dashboard_internal_url',
				'http://wg-dashboard:8185'
			),
			'dashboard_enabled' => $this->httpClient->isEnabled(),
			'wg_easy_admin_url' => $this->config->getAppValue(
				Application::APP_ID,
				'wg_easy_admin_url',
				'https://vpn-vdroners.ddns.net/'
			),
			'dashboard_proxy_connect_timeout' => $this->config->getAppValue(
				Application::APP_ID,
				'dashboard_proxy_connect_timeout',
				'5'
			),
			'dashboard_proxy_timeout' => $this->config->getAppValue(
				Application::APP_ID,
				'dashboard_proxy_timeout',
				'30'
			),
			'watchdog_enabled' => $this->config->getAppValue(
				Application::APP_ID,
				'watchdog_enabled',
				'1'
			) === '1',
			'watchdog_interval_minutes' => $this->config->getAppValue(
				Application::APP_ID,
				'watchdog_interval_minutes',
				'5'
			),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function saveSettings(): JSONResponse
	{
		if ($deny = $this->requireAdmin()) {
			return $deny;
		}
		$body = file_get_contents('php://input');
		$data = json_decode((string) $body, true);
		if (!is_array($data)) {
			return new JSONResponse(['error' => 'Invalid JSON'], Http::STATUS_BAD_REQUEST);
		}

		if (isset($data['dashboard_internal_url'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'dashboard_internal_url',
				trim((string) $data['dashboard_internal_url'])
			);
		}
		if (array_key_exists('dashboard_enabled', $data)) {
			$this->config->setAppValue(
				Application::APP_ID,
				'dashboard_enabled',
				$data['dashboard_enabled'] ? '1' : '0'
			);
		}
		if (isset($data['wg_easy_admin_url'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'wg_easy_admin_url',
				trim((string) $data['wg_easy_admin_url'])
			);
		}
		if (isset($data['dashboard_proxy_connect_timeout'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'dashboard_proxy_connect_timeout',
				(string) max(1, min(30, (int) $data['dashboard_proxy_connect_timeout']))
			);
		}
		if (isset($data['dashboard_proxy_timeout'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'dashboard_proxy_timeout',
				(string) max(5, min(120, (int) $data['dashboard_proxy_timeout']))
			);
		}
		if (array_key_exists('watchdog_enabled', $data)) {
			$this->config->setAppValue(
				Application::APP_ID,
				'watchdog_enabled',
				$data['watchdog_enabled'] ? '1' : '0'
			);
		}
		if (isset($data['watchdog_interval_minutes'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'watchdog_interval_minutes',
				(string) max(1, min(60, (int) $data['watchdog_interval_minutes']))
			);
		}

		return $this->getSettings();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function testConnection(): JSONResponse
	{
		if ($deny = $this->requireAdmin()) {
			return $deny;
		}
		$result = $this->httpClient->get('/api/health');
		if (!$result['ok']) {
			return new JSONResponse([
				'ok' => false,
				'error' => $result['error'] ?: 'Connection failed',
			], Http::STATUS_BAD_GATEWAY);
		}
		$data = json_decode((string) $result['body'], true);
		return new JSONResponse([
			'ok' => is_array($data) && ($data['status'] ?? '') === 'ok',
			'health' => $data,
		]);
	}
}
