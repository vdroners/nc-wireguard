<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\OtlRedeemRateLimiter;
use OCA\NcWireguard\Service\OtlRedeemTracker;
use OCA\NcWireguard\Service\PeerFieldValidator;
use OCA\NcWireguard\Service\WgEasyClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Peer CRUD / OTL write surface for wg-easy (v2.2).
 *
 * Mutating routes: admin + CSRF. Public OTL redeem: PublicPage + NoCSRFRequired
 * with per-IP rate limit + NC-side single-use tracking (engine /cnf may be lenient).
 */
class PeerWriteController extends Controller
{
	public function __construct(
		IRequest $request,
		private AppSettings $appSettings,
		private WgEasyClient $wgEasyClient,
		private PeerFieldValidator $peerFieldValidator,
		private OtlRedeemRateLimiter $otlRedeemRateLimiter,
		private OtlRedeemTracker $otlRedeemTracker,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
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

	private function actorUid(): string
	{
		$user = $this->userSession->getUser();
		return $user ? $user->getUID() : 'unknown';
	}

	private function gate(): ?JSONResponse
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
		return null;
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function audit(string $action, ?int $clientId, int $httpCode, array $extra = []): void
	{
		$this->logger->info('nc_wireguard peer write', array_merge([
			'actor' => $this->actorUid(),
			'action' => $action,
			'clientId' => $clientId,
			'http_code' => $httpCode,
		], $extra));
	}

	#[AdminRequired]
	public function create(): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		$data = $this->jsonBody();
		$validated = $this->peerFieldValidator->validate($data, true);
		if ($validated['errors'] !== []) {
			return new JSONResponse(
				['error' => 'validation failed', 'fields' => $validated['errors']],
				Http::STATUS_BAD_REQUEST
			);
		}
		$fields = $validated['fields'];
		if (!array_key_exists('expiresAt', $fields)) {
			$fields['expiresAt'] = null;
		}
		$result = $this->wgEasyClient->createClient([
			'name' => $fields['name'],
			'expiresAt' => $fields['expiresAt'],
		]);
		$this->audit('create', $result['clientId'] ?? null, $result['http_code']);
		if (!$result['ok'] || empty($result['clientId'])) {
			return $this->errorFromResult($result);
		}
		$clientId = (int) $result['clientId'];

		// Apply optional tunnel fields via update (create only accepts name/expiresAt).
		$updateFields = $fields;
		unset($updateFields['name'], $updateFields['expiresAt']);
		if ($updateFields !== []) {
			$upd = $this->wgEasyClient->updateClient($clientId, $updateFields);
			$this->audit('create_update', $clientId, $upd['http_code']);
			if (!$upd['ok']) {
				return $this->errorFromResult($upd);
			}
		}

		$client = $this->wgEasyClient->getClient($clientId);
		return new JSONResponse([
			'success' => true,
			'clientId' => $clientId,
			'client' => $client,
		], Http::STATUS_CREATED);
	}

	#[AdminRequired]
	public function update(int $clientId): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		if ($clientId < 1) {
			return new JSONResponse(['error' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}
		$data = $this->jsonBody();
		$validated = $this->peerFieldValidator->validate($data, false);
		if ($validated['errors'] !== []) {
			return new JSONResponse(
				['error' => 'validation failed', 'fields' => $validated['errors']],
				Http::STATUS_BAD_REQUEST
			);
		}
		$fields = $validated['fields'];
		if (array_key_exists('enabled', $data)) {
			$fields['enabled'] = (bool) $data['enabled'];
		}
		if ($fields === []) {
			return new JSONResponse(['error' => 'No fields to update'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->wgEasyClient->updateClient($clientId, $fields);
		$this->audit('update', $clientId, $result['http_code']);
		if (!$result['ok']) {
			return $this->errorFromResult($result);
		}
		return new JSONResponse([
			'success' => true,
			'client' => $this->wgEasyClient->getClient($clientId),
		]);
	}

	#[AdminRequired]
	public function destroy(int $clientId): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		if ($clientId < 1) {
			return new JSONResponse(['error' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->wgEasyClient->deleteClient($clientId);
		$this->audit('delete', $clientId, $result['http_code']);
		if (!$result['ok']) {
			return $this->errorFromResult($result);
		}
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function enable(int $clientId): JSONResponse
	{
		return $this->toggle($clientId, true);
	}

	#[AdminRequired]
	public function disable(int $clientId): JSONResponse
	{
		return $this->toggle($clientId, false);
	}

	private function toggle(int $clientId, bool $enable): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		if ($clientId < 1) {
			return new JSONResponse(['error' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}
		$result = $enable
			? $this->wgEasyClient->enableClient($clientId)
			: $this->wgEasyClient->disableClient($clientId);
		$this->audit($enable ? 'enable' : 'disable', $clientId, $result['http_code']);
		if (!$result['ok']) {
			return $this->errorFromResult($result);
		}
		return new JSONResponse(['success' => true]);
	}

	#[AdminRequired]
	public function oneTimeLink(int $clientId): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		if ($clientId < 1) {
			return new JSONResponse(['error' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->wgEasyClient->generateOneTimeLink($clientId);
		$this->audit('otl_generate', $clientId, $result['http_code']);
		if (!$result['ok']) {
			return $this->errorFromResult($result);
		}
		$token = $result['oneTimeLink'] ?? null;
		$ncRedeem = null;
		if (is_string($token) && $token !== '') {
			// linkToRoute can return empty in some CLI/proxy setups; build the
			// public path explicitly so mint always yields a shareable URL.
			$path = '/index.php/apps/' . Application::APP_ID . '/api/peers/otl/' . rawurlencode($token);
			$ncRedeem = $this->urlGenerator->getAbsoluteURL($path);
		}
		return new JSONResponse([
			'success' => true,
			'oneTimeLink' => $token,
			'redeemPath' => $result['redeemPath'] ?? null,
			'redeemUrl' => $ncRedeem,
			// wg-easy expires the token ~5 minutes after generation.
			'expiresAt' => $result['expiresAt'] ?? null,
		]);
	}

	/**
	 * Public one-shot redeem — shareable with field users (no NC login).
	 * Mint stays admin+CSRF; engine token is single-use (~5 min TTL).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function redeemOtl(string $token): DataDownloadResponse|JSONResponse
	{
		if (!$this->appSettings->isDashboardEnabled()) {
			return new JSONResponse(
				['message' => 'Disabled', 'reason' => 'disabled'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$ip = $this->request->getRemoteAddress();
		$limit = $this->otlRedeemRateLimiter->attempt(is_string($ip) ? $ip : '');
		if (!$limit['allowed']) {
			$this->audit('otl_redeem_rate_limited', null, 429, [
				'token_len' => strlen($token),
				'retry_after' => $limit['retry_after'],
			]);
			$resp = new JSONResponse(
				['error' => 'Too many redeem attempts', 'reason' => 'rate_limited'],
				Http::STATUS_TOO_MANY_REQUESTS
			);
			$resp->addHeader('Retry-After', (string) $limit['retry_after']);
			return $resp;
		}

		if ($this->otlRedeemTracker->wasRedeemed($token)) {
			$this->audit('otl_redeem_replay', null, 410, ['token_len' => strlen($token)]);
			return new JSONResponse(
				['error' => 'One-time link already used', 'reason' => 'already_redeemed'],
				Http::STATUS_GONE
			);
		}

		$result = $this->wgEasyClient->redeemOneTimeLink($token);
		$this->audit('otl_redeem', null, $result['http_code'], [
			'token_len' => strlen($token),
			'public' => true,
		]);
		if (!$result['ok'] || $result['body'] === false) {
			return new JSONResponse(
				['error' => $result['error'] !== '' ? $result['error'] : 'redeem failed'],
				$result['http_code'] >= 400 ? $result['http_code'] : Http::STATUS_BAD_GATEWAY
			);
		}
		$this->otlRedeemTracker->markRedeemed($token);
		return new DataDownloadResponse(
			(string) $result['body'],
			'peer.conf',
			'text/plain'
		);
	}

	#[AdminRequired]
	public function configuration(int $clientId): JSONResponse
	{
		if ($resp = $this->gate()) {
			return $resp;
		}
		if ($clientId < 1) {
			return new JSONResponse(['error' => 'Invalid client id'], Http::STATUS_BAD_REQUEST);
		}
		$result = $this->wgEasyClient->getClientConfiguration($clientId);
		if (!$result['ok'] || $result['body'] === false) {
			return new JSONResponse(
				['error' => 'configuration fetch failed'],
				$result['http_code'] >= 400 ? $result['http_code'] : Http::STATUS_BAD_GATEWAY
			);
		}
		$data = $this->wgEasyClient->formatConfigurationBody(
			(string) $result['body'],
			$result['is_json']
		);
		return new JSONResponse($data);
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input');
		$data = json_decode((string) $raw, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array{ok?: bool, http_code: int, error?: string, code?: string} $result
	 */
	private function errorFromResult(array $result): JSONResponse
	{
		$code = $result['http_code'] >= 400 ? $result['http_code'] : Http::STATUS_BAD_GATEWAY;
		$body = [
			'error' => $result['error'] ?? 'request failed',
		];
		if (!empty($result['code'])) {
			$body['code'] = $result['code'];
		}
		return new JSONResponse($body, $code);
	}
}
