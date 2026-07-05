<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeHealthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller
{
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private AppSettings $appSettings,
		private NativeHealthService $nativeHealth,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function isAdmin(): bool
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			return false;
		}
		return $this->groupManager->isAdmin($user->getUID());
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse
	{
		$enabled = $this->appSettings->isDashboardEnabled();
		$appVersion = $this->config->getAppValue(Application::APP_ID, 'installed_version', '');
		$nativeOk = false;
		$wgEasyOk = false;
		$pollerOk = false;
		$hostMetricsOk = false;
		$health = null;

		if ($this->isAdmin() && $enabled) {
			$health = $this->nativeHealth->getHealth($appVersion);
			$nativeOk = ($health['status'] ?? '') === 'ok';
			$wgEasyOk = (bool) ($health['wg_easy'] ?? false);
			$pollerOk = (bool) ($health['poller'] ?? false);
			$hostMetricsOk = (bool) ($health['host_metrics'] ?? false);
		}

		$payload = [
			'app_id' => Application::APP_ID,
			'version' => $appVersion,
			'enabled' => $enabled,
			'native_ok' => $nativeOk,
			'wg_easy_ok' => $wgEasyOk,
			'poller_ok' => $pollerOk,
			'host_metrics_ok' => $hostMetricsOk,
			'is_admin' => $this->isAdmin(),
			'health' => $health,
		];
		if ($this->isAdmin()) {
			$payload['wg_easy_admin_url'] = trim((string) $this->config->getAppValue(
				Application::APP_ID,
				'wg_easy_admin_url',
				'',
			));
		}

		return new JSONResponse($payload);
	}
}
