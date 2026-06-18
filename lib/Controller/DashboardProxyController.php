<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\DashboardHttpClient;
use OCA\NcWireguard\Service\PathSanitizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class DashboardProxyController extends Controller
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

		if (!$this->httpClient->isEnabled()) {
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

		$query = $this->sanitizeQueryString(
			$this->request->server['QUERY_STRING'] ?? ''
		);

		$result = $this->httpClient->get('/api/' . $safePath, $query);
		if (!$result['ok']) {
			$this->logger->error('Dashboard proxy unreachable', [
				'path' => $safePath,
				'error' => $result['error'],
			]);
			return new JSONResponse(
				['error' => 'Dashboard sidecar unreachable', 'reason' => 'backend_unreachable'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		$data = json_decode((string) $result['body'], true);
		if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
			return new JSONResponse(
				['error' => 'Invalid JSON from sidecar'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		return new JSONResponse($data, $result['http_code'] ?: Http::STATUS_OK);
	}

	private function sanitizeQueryString(string $raw): string
	{
		if ($raw === '') {
			return '';
		}
		parse_str($raw, $params);
		unset($params['_route'], $params['_url']);
		if (empty($params)) {
			return '';
		}
		return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}
}
