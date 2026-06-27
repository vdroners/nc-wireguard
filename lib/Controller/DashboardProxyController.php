<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeDashboardService;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\PathSanitizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Native dashboard API (summary, bandwidth, connections, geoip, system, health).
 */
class DashboardProxyController extends Controller
{
	public function __construct(
		IRequest $request,
		private AppSettings $appSettings,
		private NativeDashboardService $nativeDashboard,
		private NativeHealthService $nativeHealth,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
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

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function proxy(string $path): Response
	{
		if (!$this->isAdmin()) {
			return new JSONResponse(
				[
					'message' => 'WireGuard dashboard is restricted to Nextcloud administrators.',
					'reason' => 'no_permission',
				],
				Http::STATUS_FORBIDDEN
			);
		}

		if (!$this->appSettings->isDashboardEnabled()) {
			return new JSONResponse(
				[
					'message' => 'WireGuard dashboard is disabled in NC WireGuard settings.',
					'reason' => 'disabled',
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if (PathSanitizer::hasTraversalAttempt($path)) {
			$this->logger->warning('DashboardProxyController blocked traversal', [
				'path' => $path,
				'actor' => $this->userSession->getUser()?->getUID() ?? '-',
			]);
			return new JSONResponse(
				['message' => 'Invalid path', 'reason' => 'bad_path'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$safePath = PathSanitizer::normalize($path);
		$allowed = ['summary', 'bandwidth', 'connections', 'geoip', 'system', 'health'];
		$root = explode('/', $safePath)[0] ?? '';
		if (!in_array($root, $allowed, true)) {
			return new JSONResponse(
				['message' => 'Path not allowed', 'reason' => 'bad_path'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->serveNative($root);
	}

	private function serveNative(string $root): Response
	{
		$params = $this->request->getParams();
		$appVersion = $this->config->getAppValue(Application::APP_ID, 'installed_version', '');

		switch ($root) {
			case 'summary':
				$data = $this->nativeDashboard->buildSummary();
				if (isset($data['error'])) {
					return new JSONResponse($data, Http::STATUS_BAD_GATEWAY);
				}
				return new JSONResponse($data);

			case 'bandwidth':
				$hours = isset($params['hours']) ? (int) $params['hours'] : 24;
				$clientId = isset($params['client_id']) ? (int) $params['client_id'] : null;
				return new JSONResponse($this->nativeDashboard->fetchBandwidth($hours, $clientId));

			case 'connections':
				$days = isset($params['days']) ? (int) $params['days'] : 7;
				$clientId = isset($params['client_id']) ? (int) $params['client_id'] : null;
				return new JSONResponse($this->nativeDashboard->fetchConnections($days, $clientId));

			case 'geoip':
				return new JSONResponse($this->nativeDashboard->fetchGeoip());

			case 'system':
				$hours = isset($params['hours']) ? (int) $params['hours'] : 24;
				return new JSONResponse($this->nativeDashboard->fetchSystem($hours));

			case 'health':
				return new JSONResponse($this->nativeHealth->getHealth($appVersion));

			default:
				return new JSONResponse(
					['message' => 'Path not allowed', 'reason' => 'bad_path'],
					Http::STATUS_BAD_REQUEST
				);
		}
	}
}
