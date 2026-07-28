<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Util\DockerUrlResolver;
use OCP\IConfig;

/**
 * Typed accessors for nc_wireguard appconfig keys.
 */
class AppSettings
{
	/** wg-easy owns the dataplane (production default). */
	public const ENGINE_WG_EASY = 'wgeasy';

	/** NC drives kernel WireGuard through the wg-sync sidecar (P5). */
	public const ENGINE_NATIVE = 'native';

	/** One-time links are minted and redeemed by the engine (production default). */
	public const OTL_SOURCE_WG_EASY = 'wgeasy';

	/** Nextcloud mints its own one-time links from the peer store (P4). */
	public const OTL_SOURCE_NC = 'nc';

	/**
	 * Engine-agnostic appconfig names and the `wg_easy_*` keys they replace.
	 *
	 * P6 renames the settings, but a rename that breaks an operator's existing
	 * `occ config:app:set` muscle memory (and any scripted deploy) on upgrade
	 * day is not worth the tidiness. Reads prefer the new name and fall back to
	 * the legacy one; writes stay on the legacy key for one minor so a rollback
	 * to the previous app version still sees the value. See
	 * `docs/ops/ENGINE_CUTOVER.md`.
	 *
	 * @var array<string, string> new name => legacy name
	 */
	public const SETTING_ALIASES = [
		'engine_api_url' => 'wg_easy_api_url',
		'engine_username' => 'wg_easy_username',
		'engine_password' => 'wg_easy_password',
		'engine_admin_url' => 'wg_easy_admin_url',
		'hide_engine_admin_link' => 'hide_wg_easy_admin_link',
	];

	public function __construct(
		private IConfig $config,
		private SecretCrypto $secrets,
		private DockerUrlResolver $urlResolver,
	) {
	}

	/**
	 * Which backend owns peers and key material.
	 *
	 * Stays `wgeasy` until the NC peer store is complete and the sidecar is
	 * verified; an unrecognised value falls back to `wgeasy` so a bad appconfig
	 * write cannot point production at a half-built engine.
	 */
	public function getEngine(): string
	{
		$raw = strtolower(trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'engine',
			self::ENGINE_WG_EASY
		)));
		return in_array($raw, [self::ENGINE_WG_EASY, self::ENGINE_NATIVE], true)
			? $raw
			: self::ENGINE_WG_EASY;
	}

	public function setEngine(string $engine): void
	{
		$value = strtolower(trim($engine));
		if (!in_array($value, [self::ENGINE_WG_EASY, self::ENGINE_NATIVE], true)) {
			throw new \InvalidArgumentException('Unknown engine: ' . $engine);
		}
		$this->config->setAppValue(Application::APP_ID, 'engine', $value);
	}

	/**
	 * Who mints one-time config links. Independent of `engine` so NC-native OTL
	 * can be exercised (P4) while wg-easy still owns the tunnel.
	 */
	public function getOtlSource(): string
	{
		$raw = strtolower(trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'otl_source',
			self::OTL_SOURCE_WG_EASY
		)));
		return in_array($raw, [self::OTL_SOURCE_WG_EASY, self::OTL_SOURCE_NC], true)
			? $raw
			: self::OTL_SOURCE_WG_EASY;
	}

	public function setOtlSource(string $source): void
	{
		$value = strtolower(trim($source));
		if (!in_array($value, [self::OTL_SOURCE_WG_EASY, self::OTL_SOURCE_NC], true)) {
			throw new \InvalidArgumentException('Unknown OTL source: ' . $source);
		}
		$this->config->setAppValue(Application::APP_ID, 'otl_source', $value);
	}

	public function isDashboardEnabled(): bool
	{
		$raw = trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'dashboard_enabled',
			'1'
		));
		return $raw === '1' || strtolower($raw) === 'true';
	}

	public function setDashboardEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'dashboard_enabled', $enabled ? '1' : '0');
	}

	/**
	 * Browser-reachable wg-easy UI base URL (empty when not published).
	 *
	 * Distinct from getWgEasyApiUrl(), which is the container-internal address
	 * the poller uses and is not resolvable from an operator's browser.
	 */
	public function getWgEasyAdminUrl(): string
	{
		return rtrim($this->aliased('engine_admin_url'), '/');
	}

	public function setWgEasyAdminUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_easy_admin_url', trim($url));
	}

	/**
	 * Hide the "open wg-easy" deep links in the dashboard.
	 *
	 * Defaults to enabled from v2.1: Nextcloud is the peer controller, and the
	 * wg-easy admin UI is expected to be unpublished after cutover.
	 */
	public function isWgEasyAdminLinkHidden(): bool
	{
		$raw = $this->aliased('hide_engine_admin_link', '1');
		return $raw === '1' || strtolower($raw) === 'true';
	}

	public function setWgEasyAdminLinkHidden(bool $hidden): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'hide_wg_easy_admin_link',
			$hidden ? '1' : '0'
		);
	}

	public function getWgEasyApiUrl(): string
	{
		$url = $this->aliased('engine_api_url');
		if ($url === '') {
			return '';
		}
		return $this->urlResolver->resolveHostDockerInternal($url);
	}

	public function setWgEasyApiUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_easy_api_url', trim($url));
	}

	public function getWgEasyUsername(): string
	{
		$value = $this->aliased('engine_username');
		return $value === '' ? 'admin' : $value;
	}

	public function setWgEasyUsername(string $username): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_easy_username', trim($username));
	}

	public function getWgEasyPassword(): string
	{
		$renamed = $this->secrets->get('engine_password');
		return $renamed !== '' ? $renamed : $this->secrets->get('wg_easy_password');
	}

	public function setWgEasyPassword(string $password): void
	{
		$this->secrets->set('wg_easy_password', $password);
	}

	public function isWgEasyPasswordConfigured(): bool
	{
		return $this->getWgEasyPassword() !== '';
	}

	/**
	 * Base URL of the wg-sync sidecar, e.g. `http://wg_sync_lab:51821` (P5).
	 */
	public function getWgSyncUrl(): string
	{
		$url = rtrim(trim((string) $this->config->getAppValue(
			Application::APP_ID,
			'wg_sync_url',
			''
		)), '/');
		if ($url === '') {
			return '';
		}
		return $this->urlResolver->resolveHostDockerInternal($url);
	}

	public function setWgSyncUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'wg_sync_url', rtrim(trim($url), '/'));
	}

	public function getWgSyncToken(): string
	{
		return $this->secrets->get('wg_sync_token');
	}

	public function setWgSyncToken(string $token): void
	{
		$this->secrets->set('wg_sync_token', $token);
	}

	public function isWgSyncTokenConfigured(): bool
	{
		return $this->secrets->isConfigured('wg_sync_token');
	}

	/**
	 * Set once the operator has verified that every live peer is in the NC
	 * store. `engine=native` refuses to activate without it: a native engine
	 * serving a half-populated store would present the field fleet as deleted.
	 */
	public function isImportComplete(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'import_complete', '0') === '1';
	}

	public function setImportComplete(bool $complete): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'import_complete',
			$complete ? '1' : '0'
		);
	}

	/**
	 * Cutover write freeze (P6, step 1).
	 *
	 * Peer CRUD between the export and the engine swap would leave the two
	 * stores disagreeing about the fleet, and the disagreement would only
	 * surface as a peer that cannot connect. Reads, downloads, and OTL redeems
	 * stay open — a field user redeeming a link they were already sent is not
	 * what puts the two stores out of step.
	 *
	 * Deliberately CLI-only; there is no admin-UI toggle, because this should
	 * be a considered act inside a maintenance window.
	 */
	public function arePeerWritesFrozen(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'peer_writes_frozen', '0') === '1';
	}

	/**
	 * Read a setting by its engine-agnostic name, falling back to the legacy
	 * `wg_easy_*` key. See SETTING_ALIASES.
	 */
	private function aliased(string $newKey, string $default = ''): string
	{
		$value = trim((string) $this->config->getAppValue(Application::APP_ID, $newKey, ''));
		if ($value !== '') {
			return $value;
		}
		$legacy = self::SETTING_ALIASES[$newKey] ?? null;
		if ($legacy === null) {
			return $default;
		}
		$value = trim((string) $this->config->getAppValue(Application::APP_ID, $legacy, ''));
		return $value === '' ? $default : $value;
	}

	public function getPollIntervalSeconds(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'poll_interval_seconds', '30');
		return max(10, min(300, $raw));
	}

	public function setPollIntervalSeconds(int $seconds): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'poll_interval_seconds',
			(string) max(10, min(300, $seconds))
		);
	}

	public function getRetentionDays(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'retention_days', '30');
		return max(1, min(365, $raw));
	}

	public function setRetentionDays(int $days): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'retention_days',
			(string) max(1, min(365, $days))
		);
	}

	public function isGeoIpEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'geoip_enabled', '0') === '1';
	}

	public function getGeoIpProvider(): string
	{
		$raw = trim((string) $this->config->getAppValue(Application::APP_ID, 'geoip_provider', 'ip_api'));
		return in_array($raw, ['ip_api', 'custom'], true) ? $raw : 'ip_api';
	}

	public function setGeoIpProvider(string $provider): void
	{
		$this->config->setAppValue(Application::APP_ID, 'geoip_provider', $provider);
	}

	public function getGeoIpApiKey(): string
	{
		return $this->secrets->get('geoip_api_key');
	}

	public function setGeoIpApiKey(string $key): void
	{
		$this->secrets->set('geoip_api_key', $key);
	}

	public function isGeoIpApiKeyConfigured(): bool
	{
		return $this->secrets->isConfigured('geoip_api_key');
	}

	public function getGeoIpCustomUrl(): string
	{
		return trim((string) $this->config->getAppValue(Application::APP_ID, 'geoip_custom_url', ''));
	}

	public function setGeoIpCustomUrl(string $url): void
	{
		$this->config->setAppValue(Application::APP_ID, 'geoip_custom_url', trim($url));
	}

	public function setGeoIpEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'geoip_enabled', $enabled ? '1' : '0');
	}

	public function getWatchdogIntervalMinutes(): int
	{
		$raw = (int) $this->config->getAppValue(Application::APP_ID, 'watchdog_interval_minutes', '5');
		return max(1, min(60, $raw));
	}

	public function setWatchdogIntervalMinutes(int $minutes): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			'watchdog_interval_minutes',
			(string) max(1, min(60, $minutes))
		);
	}

	public function isWatchdogEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, 'watchdog_enabled', '1') === '1';
	}

	public function setWatchdogEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, 'watchdog_enabled', $enabled ? '1' : '0');
	}
}
