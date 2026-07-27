<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\WgEasyClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class WgEasyReadProxyController extends Controller
{
	public function __construct(
		IRequest $request,
		private AppSettings $appSettings,
		private WgEasyClient $wgEasyClient,
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

	/**
	 * v2.1 route naming (`/api/peers/{id}/configuration`).
	 *
	 * `configuration()` stays reachable at the legacy `/api/wg-easy/...` path so
	 * older cached frontend bundles keep working across the upgrade.
	 */
	#[NoCSRFRequired]
	#[AdminRequired]
	public function peerConfiguration(int $clientId): JSONResponse
	{
		return $this->configuration($clientId);
	}

	#[NoCSRFRequired]
	#[AdminRequired]
	public function configuration(int $clientId): JSONResponse
	{
		if (!$this->isAdmin()) {
			return new JSONResponse(
				['message' => 'Forbidden', 'reason' => 'no_permission'],
				Http::STATUS_FORBIDDEN
			);
		}

		if (!$this->appSettings->isDashboardEnabled()) {
			return new JSONResponse(
				['message' => 'Disabled', 'reason' => 'disabled'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if ($clientId < 1) {
			return new JSONResponse(['message' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->wgEasyClient->getClientConfiguration($clientId);
		if (!$result['ok'] || $result['body'] === false) {
			$this->logger->error('wg-easy configuration fetch failed', [
				'clientId' => $clientId,
				'http_code' => $result['http_code'],
			]);
			return new JSONResponse(
				['error' => 'wg-easy configuration fetch failed'],
				$result['http_code'] >= 400 ? $result['http_code'] : Http::STATUS_BAD_GATEWAY
			);
		}

		$data = $this->wgEasyClient->formatConfigurationBody(
			(string) $result['body'],
			$result['is_json']
		);
		return new JSONResponse($data);
	}
}
