<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Db\ServerConfigMapper;
use OCP\IConfig;

/**
 * The WireGuard interface private key, sealed at rest.
 *
 * Separate from `SecretCrypto` for the same reason `PeerSecretCrypto` is: that
 * class returns the stored blob when decryption fails, which for an interface
 * key means wg-sync would be handed a ciphertext and every peer in the fleet
 * would stop handshaking at once.
 *
 * At cutover this holds the **preserved** `wg0` key, which is what lets field
 * peers keep their existing configs. `nc_wg_server.server_public_key` is the
 * public half and is updated in lockstep by `store()`.
 */
class ServerKeyStore
{
	private const CONFIG_KEY = 'server_private_key_enc';

	public function __construct(
		private IConfig $config,
		private PeerSecretCrypto $crypto,
		private ServerConfigMapper $server,
	) {
	}

	public function isConfigured(): bool
	{
		return $this->rawStored() !== '';
	}

	/**
	 * @throws PeerSecretCryptoException when stored but undecryptable
	 */
	public function get(): ?string
	{
		$raw = $this->rawStored();
		return $raw === '' ? null : $this->crypto->decrypt($raw);
	}

	/**
	 * Seal a private key and record the matching public key on the server row,
	 * so the two can never drift apart.
	 *
	 * @throws \RuntimeException when the key is not a 32-byte base64 scalar
	 */
	public function store(string $privateKeyBase64): string
	{
		$private = trim($privateKeyBase64);
		if (!WireGuardKeys::isValid($private)) {
			throw new \RuntimeException('Not a valid base64 WireGuard private key');
		}
		$public = WireGuardKeys::publicFromPrivate($private);

		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			$this->crypto->encrypt($private)
		);

		$row = $this->server->load();
		if ($row !== null) {
			$row->setServerPublicKey($public);
			$this->server->save($row);
		}

		return $public;
	}

	private function rawStored(): string
	{
		return trim((string) $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, ''));
	}
}
