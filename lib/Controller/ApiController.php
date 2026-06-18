<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\DashboardHttpClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
		private DashboardHttpClient $httpClient,
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): JSONResponse
	{
		$enabled = $this->httpClient->isEnabled();
		$sidecarOk = false;
		$wgEasyOk = false;
		$sidecarVersion = '';
		$health = null;

		if ($this->isAdmin() && $enabled) {
			$result = $this->httpClient->get('/api/health');
			if ($result['ok'] && $result['body'] !== false) {
				$health = json_decode((string) $result['body'], true);
				if (is_array($health)) {
					$sidecarOk = ($health['status'] ?? '') === 'ok';
					$wgEasyOk = (bool) ($health['wg_easy'] ?? false);
					$sidecarVersion = (string) ($health['version'] ?? '');
				}
			}
		}

		return new JSONResponse([
			'app_id' => Application::APP_ID,
			'version' => $this->config->getAppValue(Application::APP_ID, 'installed_version', ''),
			'enabled' => $enabled,
			'sidecar_ok' => $sidecarOk,
			'wg_easy_ok' => $wgEasyOk,
			'sidecar_version' => $sidecarVersion,
			'upstream_url' => $this->httpClient->getBaseUrl(),
			'wg_easy_admin_url' => $this->config->getAppValue(
				Application::APP_ID,
				'wg_easy_admin_url',
				'https://vpn-vdroners.ddns.net/'
			),
			'is_admin' => $this->isAdmin(),
			'health' => $health,
		]);
	}
}
