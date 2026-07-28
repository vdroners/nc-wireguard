<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts wg-easy credentials at rest in oc_appconfig.
 */
class SecretCrypto
{
	public const PREFIX = 'enc:v1:';

	/** @var list<string> */
	public const SECRET_KEYS = [
		'wg_easy_password',
		// P6 rename of wg_easy_password; both are read, see AppSettings::SETTING_ALIASES.
		'engine_password',
		'wg_sync_token',
		'geoip_api_key',
	];

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public static function isSecretKey(string $key): bool
	{
		return in_array($key, self::SECRET_KEYS, true);
	}

	public function get(string $key, string $default = ''): string
	{
		$raw = (string) $this->config->getAppValue(Application::APP_ID, $key, $default);
		return $this->decrypt($raw);
	}

	public function set(string $key, string $plain): void
	{
		if ($plain === '') {
			$this->config->deleteAppValue(Application::APP_ID, $key);
			return;
		}
		$this->config->setAppValue(
			Application::APP_ID,
			$key,
			self::PREFIX . $this->crypto->encrypt($plain)
		);
	}

	public function isConfigured(string $key): bool
	{
		return $this->get($key) !== '';
	}

	public function decrypt(string $stored): string
	{
		if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
			return $stored;
		}
		$payload = substr($stored, strlen(self::PREFIX));
		try {
			return $this->crypto->decrypt($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'nc_wireguard SecretCrypto: decrypt failed (returning raw): {err}',
				['err' => $e->getMessage()]
			);
			return $stored;
		}
	}
}
