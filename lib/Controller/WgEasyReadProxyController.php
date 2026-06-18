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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class WgEasyReadProxyController extends Controller
{
	public function __construct(
		IRequest $request,
		private DashboardHttpClient $httpClient,
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
	public function configuration(int $clientId): JSONResponse
	{
		if (!$this->isAdmin()) {
			return new JSONResponse(
				['message' => 'Forbidden', 'reason' => 'no_permission'],
				Http::STATUS_FORBIDDEN
			);
		}

		if (!$this->httpClient->isEnabled()) {
			return new JSONResponse(
				['message' => 'Disabled', 'reason' => 'disabled'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if ($clientId < 1) {
			return new JSONResponse(['message' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->httpClient->get('/api/wg/client/' . $clientId . '/configuration');
		if (!$result['ok']) {
			$this->logger->error('WgEasyReadProxy unreachable', ['clientId' => $clientId]);
			return new JSONResponse(
				['error' => 'Sidecar unreachable'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		$data = json_decode((string) $result['body'], true);
		if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
			return new JSONResponse(['error' => 'Invalid response'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($data, $result['http_code'] ?: Http::STATUS_OK);
	}
}
