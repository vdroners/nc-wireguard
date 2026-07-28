<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTime;
use DateTimeZone;
use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Db\PeerSecret;
use OCA\NcWireguard\Db\PeerSecretMapper;
use OCA\NcWireguard\Util\EnginePeerRow;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writes peers into the NC store.
 *
 * While `engine=wgeasy` (the default) this is a **shadow** store: wg-easy stays
 * the source of truth for the dataplane and nothing here is pushed back to it.
 * The store exists so the eventual native engine has peer identity, key
 * material, and addressing already in Nextcloud when it takes over.
 *
 * Two deliberate refusals: NC never generates a keypair here (a fresh key would
 * silently orphan the live peer, so a row without a public key is rejected), and
 * key material already on file is only overwritten when the caller opts in with
 * `$allowKeyRewrite`.
 */
class PeerStoreService
{
	public function __construct(
		private PeerMapper $peers,
		private PeerSecretMapper $secrets,
		private PeerSecretCrypto $crypto,
		private PeerIpam $ipam,
		private AppSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * True while the engine still owns peers, i.e. this store is a shadow copy.
	 */
	public function isShadowMode(): bool
	{
		return $this->settings->getEngine() === AppSettings::ENGINE_WG_EASY;
	}

	/**
	 * Upsert a raw engine peer record (wg-easy camelCase shape).
	 *
	 * @param array<string, mixed> $enginePeer
	 */
	public function upsertFromEngine(array $enginePeer, bool $allowKeyRewrite = false): Peer
	{
		return $this->upsert(EnginePeerRow::fromEnginePeer($enginePeer), $allowKeyRewrite);
	}

	/**
	 * Upsert a normalized row (see EnginePeerRow).
	 *
	 * Matching order is public key first (the identity the dataplane agrees on),
	 * then the wg-easy id, so a peer whose key was rotated outside NC is updated
	 * rather than duplicated.
	 *
	 * @param array<string, mixed> $row
	 * @throws RuntimeException when the row has no public key
	 */
	public function upsert(array $row, bool $allowKeyRewrite = false): Peer
	{
		$publicKey = isset($row['public_key']) && is_string($row['public_key'])
			? trim($row['public_key'])
			: '';
		if ($publicKey === '') {
			throw new RuntimeException(
				'Refusing to store a peer without a public key (no silent key generation)'
			);
		}

		$wgEasyId = isset($row['wg_easy_id']) && is_numeric($row['wg_easy_id'])
			? (int) $row['wg_easy_id']
			: null;

		$existing = $this->peers->findByPublicKey($publicKey);
		if ($existing === null && $wgEasyId !== null) {
			$existing = $this->peers->findByWgEasyId($wgEasyId);
		}

		$now = new DateTime('now', new DateTimeZone('UTC'));
		$peer = $existing ?? new Peer();
		if ($existing === null) {
			$peer->setUuid(self::newUuid());
			$peer->setCreatedAt($now);
		}

		$peer->setPublicKey($publicKey);
		$peer->setWgEasyId($wgEasyId);
		$peer->setName($this->nameOf($row, $existing));
		$peer->setEnabled((bool) ($row['enabled'] ?? true));
		$peer->setIpv4($this->addressFor($row, $existing));
		$peer->setAllowedIps($this->text($row, 'allowed_ips'));
		$peer->setDns($this->text($row, 'dns'));
		$peer->setMtu($this->number($row, 'mtu'));
		$peer->setPersistentKeepalive($this->number($row, 'persistent_keepalive'));
		$peer->setServerEndpoint($this->text($row, 'server_endpoint'));
		$peer->setServerAllowedIps($this->text($row, 'server_allowed_ips'));
		$peer->setFirewallIps($this->text($row, 'firewall_ips'));
		$peer->setHasAmnezia((bool) ($row['has_amnezia'] ?? false));
		$peer->setUpdatedAt($now);

		$peer = $existing === null ? $this->peers->insert($peer) : $this->peers->update($peer);

		$this->storeSecrets($peer, $row, $allowKeyRewrite);

		if ($peer->getHasAmnezia()) {
			$this->logger->warning(
				'nc_wireguard peer store: peer {uuid} carries Amnezia obfuscation; the native engine must refuse it',
				['uuid' => $peer->getUuid()]
			);
		}

		return $peer;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function storeSecrets(Peer $peer, array $row, bool $allowKeyRewrite): void
	{
		$peerId = (int) $peer->getId();
		$existing = $this->secrets->findByPeerId($peerId);

		$privateKey = isset($row['private_key']) && is_string($row['private_key'])
			? trim($row['private_key'])
			: '';
		if ($privateKey === '') {
			if ($existing === null) {
				$this->logger->warning(
					'nc_wireguard peer store: peer {uuid} stored without key material '
					. '(source had no private key); config generation stays on the engine',
					['uuid' => $peer->getUuid()]
				);
			}
			return;
		}

		if ($existing !== null && !$allowKeyRewrite) {
			// Import runs are re-runnable; overwriting a stored key on every pass
			// would let a stale export silently replace good key material.
			$this->logger->debug(
				'nc_wireguard peer store: keeping stored key material for peer {uuid}',
				['uuid' => $peer->getUuid()]
			);
			return;
		}

		$psk = isset($row['psk']) && is_string($row['psk']) && trim($row['psk']) !== ''
			? trim($row['psk'])
			: null;

		$secret = $existing ?? new PeerSecret();
		$secret->setPeerId($peerId);
		$secret->setPrivateKeyEnc($this->crypto->encrypt($privateKey));
		$secret->setPskEnc($psk === null ? null : $this->crypto->encrypt($psk));
		$this->secrets->save($secret, $existing !== null);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function nameOf(array $row, ?Peer $existing): string
	{
		$name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
		if ($name !== '') {
			return mb_substr($name, 0, 255);
		}
		return $existing?->getName() ?? 'peer';
	}

	/**
	 * Keep an assignment that already exists, take what the source reported,
	 * otherwise let IPAM pick. The break-glass peer never gets re-addressed.
	 *
	 * @param array<string, mixed> $row
	 */
	private function addressFor(array $row, ?Peer $existing): ?string
	{
		$fromRow = isset($row['ipv4']) && is_string($row['ipv4']) ? trim($row['ipv4']) : '';
		if ($fromRow !== '') {
			return $fromRow;
		}
		$current = $existing?->getIpv4();
		if (is_string($current) && $current !== '') {
			return $current;
		}
		if (($row['break_glass'] ?? false) === true) {
			// Its address comes from the engine's own config; guessing one here
			// would be worse than leaving the field empty for the operator.
			return null;
		}
		return $this->ipam->allocate();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function text(array $row, string $key): ?string
	{
		$value = $row[$key] ?? null;
		if (is_array($value)) {
			$value = implode(', ', array_filter($value, 'is_string'));
		}
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return $value === '' ? null : $value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function number(array $row, string $key): ?int
	{
		$value = $row[$key] ?? null;
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
			return (int) trim($value);
		}
		return null;
	}

	/**
	 * RFC 4122 v4 identifier. Local so the store has no OCP dependency beyond
	 * the mappers, which keeps it unit-testable without a Nextcloud container.
	 */
	public static function newUuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
