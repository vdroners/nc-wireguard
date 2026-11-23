<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Db\MetricsHeartbeatMapper;
use OCA\NcWireguard\Db\PollStateMapper;
use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\NativeHealthService;
use OCA\NcWireguard\Service\WgEasyClient;
use OCA\NcWireguard\Service\WgEasyProbe;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller
{
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private AppSettings $appSettings,
		private NativeHealthService $nativeHealth,
		private WgEasyProbe $wgEasyProbe,
		private MetricsHeartbeatMapper $heartbeatMapper,
		private PollStateMapper $pollStateMapper,
		private WgEasyClient $wgEasyClient,
		private LoggerInterface $logger,
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
	#[PasswordConfirmationRequired]
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

	/**
	 * Admin health card: timestamps, ok flags, peer handshake counts, legacy key names.
	 * Never returns secrets, endpoints, IPs, tokens, or peer keys.
	 */
	#[AdminRequired]
	#[NoCSRFRequired]
	public function getHealth(): JSONResponse
	{
		$heartbeat = $this->heartbeatMapper->findSingleton();
		$legacyKeys = $this->appSettings->listPresentLegacyKeys();

		if ($heartbeat === null) {
			return new JSONResponse([
				'status' => 'unknown',
				'known' => false,
				'status_reason' => 'no heartbeat row yet — poller has not succeeded',
				'last_poll_at' => null,
				'wg_easy_ok' => null,
				'error_message' => null,
				'peers_total' => null,
				'peers_connected' => null,
				'peers_not_connected' => null,
				'peers_never_handshake' => null,
				'legacy_keys_present' => $legacyKeys,
			]);
		}

		$stats = $this->pollStateMapper->countHandshakeStats();
		$lastPollAt = $heartbeat->getLastPollAt();
		$pollInterval = $this->appSettings->getPollIntervalSeconds();
		$staleThreshold = max(60, $pollInterval * 2);
		$age = $lastPollAt !== null ? (time() - $lastPollAt->getTimestamp()) : PHP_INT_MAX;
		$stale = $age > $staleThreshold;

		if ($stale) {
			return new JSONResponse([
				'status' => 'unknown',
				'known' => true,
				'status_reason' => 'last poll older than ' . $staleThreshold . 's — treating as unknown',
				'last_poll_at' => $lastPollAt !== null ? $lastPollAt->format('c') : null,
				'wg_easy_ok' => null,
				'error_message' => $heartbeat->getErrorMessage(),
				'peers_total' => $stats['total'],
				'peers_connected' => $stats['connected'],
				'peers_not_connected' => $stats['not_connected'],
				'peers_never_handshake' => $stats['never'],
				'legacy_keys_present' => $legacyKeys,
			]);
		}

		return new JSONResponse([
			'status' => $heartbeat->getSuccess() ? 'ok' : 'degraded',
			'known' => true,
			'status_reason' => null,
			'last_poll_at' => $lastPollAt !== null ? $lastPollAt->format('c') : null,
			'wg_easy_ok' => $heartbeat->getWgEasyOk(),
			'error_message' => $heartbeat->getErrorMessage(),
			'peers_total' => $stats['total'],
			'peers_connected' => $stats['connected'],
			'peers_not_connected' => $stats['not_connected'],
			'peers_never_handshake' => $stats['never'],
			'legacy_keys_present' => $legacyKeys,
		]);
	}

	/**
	 * Current VPN endpoint host/port from wg-easy userconfig (admin read).
	 */
	#[AdminRequired]
	#[NoCSRFRequired]
	public function getEndpoint(): JSONResponse
	{
		$result = $this->wgEasyClient->getUserConfig();
		if (!$result['ok'] || !isset($result['config']) || !is_array($result['config'])) {
			return new JSONResponse([
				'ok' => false,
				'error' => 'endpoint unavailable',
				'host' => null,
				'port' => null,
			], Http::STATUS_BAD_GATEWAY);
		}
		$config = $result['config'];
		$host = isset($config['host']) && is_string($config['host']) ? $config['host'] : null;
		$port = isset($config['port']) && is_numeric($config['port']) ? (int) $config['port'] : null;
		return new JSONResponse([
			'ok' => true,
			'host' => $host,
			'port' => $port,
		]);
	}

	/**
	 * Update VPN endpoint host only (port unchanged). Password confirmation required.
	 */
	#[AdminRequired]
	#[PasswordConfirmationRequired]
	public function updateEndpoint(): JSONResponse
	{
		$data = $this->readJsonBody();
		if (!is_array($data)) {
			return new JSONResponse(['error' => 'Invalid JSON'], Http::STATUS_BAD_REQUEST);
		}
		$host = isset($data['host']) ? trim((string) $data['host']) : '';
		if (!self::isValidEndpointHost($host)) {
			return new JSONResponse([
				'error' => 'Invalid hostname — use an RFC 1123 hostname or IPv4 (no scheme, port, or spaces)',
			], Http::STATUS_BAD_REQUEST);
		}

		$before = $this->wgEasyClient->getUserConfig();
		$oldHost = null;
		if ($before['ok'] && isset($before['config']['host']) && is_string($before['config']['host'])) {
			$oldHost = $before['config']['host'];
		}

		$result = $this->wgEasyClient->updateEndpointHost($host);
		$this->logger->info('nc_wireguard endpoint host change', [
			'old_host' => $oldHost,
			'new_host' => $host,
			'ok' => $result['ok'],
		]);

		if (!$result['ok']) {
			return new JSONResponse([
				'error' => $result['error'] ?? 'endpoint update failed',
			], Http::STATUS_BAD_GATEWAY);
		}

		$port = null;
		if ($before['ok'] && isset($before['config']['port']) && is_numeric($before['config']['port'])) {
			$port = (int) $before['config']['port'];
		}

		return new JSONResponse([
			'ok' => true,
			'host' => $host,
			'port' => $port,
		]);
	}

	/**
	 * RFC 1123 hostname or IPv4 — no scheme, port, path, or spaces.
	 */
	public static function isValidEndpointHost(string $host): bool
	{
		$host = trim($host);
		if ($host === '' || strlen($host) > 253) {
			return false;
		}
		if (preg_match('/[\s\/:#\?\\\\]/', $host) === 1) {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return true;
		}
		// Single-label or dotted labels; each 1–63 chars, alnum or hyphen, no leading/trailing hyphen.
		return preg_match(
			'/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/',
			$host
		) === 1;
	}

	/**
	 * @return mixed
	 */
	protected function readJsonBody(): mixed
	{
		$body = file_get_contents('php://input');
		return json_decode((string) $body, true);
	}

	#[AdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
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
	#[UserRateLimit(limit: 10, period: 60)]
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
