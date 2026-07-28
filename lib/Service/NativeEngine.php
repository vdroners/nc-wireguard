<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTime;
use DateTimeZone;
use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Db\PeerSecret;
use OCA\NcWireguard\Db\PeerSecretMapper;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Db\ServerConfigMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Native engine (P5, lab only): the NC peer store is the source of truth and
 * the wg-sync sidecar is a dumb applier.
 *
 * Peer ids on this engine are `nc_wg_peers.id`. Controllers keep passing the
 * integer they always passed; `PeerMapper::findByEngineId()` resolves either a
 * wg-easy id or a row id, so a bookmarked URL from before cutover still works.
 *
 * Two policies are enforced here rather than left to the sidecar:
 *
 *  - **Amnezia is refused, never dropped.** A peer carrying `jc`/`jmin`/`jmax`
 *    or `i1..i5` has no kernel-WireGuard equivalent. Silently ignoring those
 *    fields produces a peer that looks configured and cannot connect, so any
 *    write that would install one fails loudly and the peer is excluded from
 *    the applied set with an error in the log.
 *  - **IPv4 only.** No IPv6 address is assigned and `::/0` is never accepted,
 *    matching the P3 decision and `nc_wg_server.ipv4_only`.
 *
 * Production stays on `WgEasyEngine` until an operator runs the P6 cutover;
 * `EngineResolver` decides which one is registered.
 */
class NativeEngine implements WireGuardEngineInterface
{
	public const ERR_AMNEZIA = 'AMNEZIA_UNSUPPORTED';
	public const ERR_IPV6 = 'IPV6_NOT_SUPPORTED';
	public const ERR_NO_SERVER_KEY = 'NO_SERVER_KEY';

	public function __construct(
		private PeerMapper $peers,
		private PeerSecretMapper $secrets,
		private ServerConfigMapper $server,
		private PeerSecretCrypto $crypto,
		private PeerIpam $ipam,
		private PeerConfBuilder $confBuilder,
		private ServerKeyStore $serverKeys,
		private WgSyncClient $wgSync,
		private NcOtlService $otl,
		private LoggerInterface $logger,
	) {
	}

	// --- read -------------------------------------------------------------

	public function listPeers(): ?array
	{
		$stats = $this->getRuntimeStats();
		$out = [];
		foreach ($this->peers->findAll() as $peer) {
			$out[] = $this->toEngineShape($peer, $stats[$peer->getPublicKey()] ?? null);
		}
		return $out;
	}

	public function getPeer(int $peerId): ?array
	{
		$peer = $this->peers->findByEngineId($peerId);
		if ($peer === null) {
			return null;
		}
		$stats = $this->getRuntimeStats();
		return $this->toEngineShape($peer, $stats[$peer->getPublicKey()] ?? null);
	}

	public function getRuntimeStats(): array
	{
		$result = $this->wgSync->dump();
		if (!$result['ok'] || !is_array($result['json'])) {
			return [];
		}
		$byKey = [];
		foreach ($this->peers->findAll() as $peer) {
			$byKey[(string) $peer->getPublicKey()] = $peer;
		}

		$now = time();
		$stats = [];
		foreach ($result['json']['peers'] ?? [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$publicKey = $entry['public_key'] ?? null;
			if (!is_string($publicKey) || $publicKey === '') {
				continue;
			}
			$handshake = (int) ($entry['latest_handshake'] ?? 0);
			$peer = $byKey[$publicKey] ?? null;

			$stat = [
				'public_key' => $publicKey,
				'transfer_rx' => (int) ($entry['transfer_rx'] ?? 0),
				'transfer_tx' => (int) ($entry['transfer_tx'] ?? 0),
				'endpoint' => is_string($entry['endpoint'] ?? null) ? $entry['endpoint'] : null,
				'latest_handshake' => $handshake > 0 ? gmdate('c', $handshake) : null,
				// `wg` reports the raw timestamp; "connected" is the same
				// 150 s window the wg-easy state machine already uses.
				'connected' => $handshake > 0 && ($now - $handshake) < 150,
			];
			if ($peer !== null) {
				$stat['peer_id'] = (int) $peer->getId();
				$stat['name'] = (string) $peer->getName();
			}
			$stats[$publicKey] = $stat;
		}
		return $stats;
	}

	public function getServerInfo(): array
	{
		$row = $this->server->load();
		$health = $this->wgSync->health();
		$sidecar = is_array($health['json']) ? $health['json'] : [];

		return [
			'ok' => $health['ok'],
			'engine' => 'native',
			'host' => $row?->getHost(),
			'port' => $row?->getPort(),
			'mtu' => $row?->getMtu(),
			'ipv4Cidr' => $row?->getCidr() ?? PeerIpam::DEFAULT_CIDR,
			'ipv6Cidr' => null,
			'interfaceName' => is_string($sidecar['interface'] ?? null) ? $sidecar['interface'] : null,
			'enabled' => (bool) ($sidecar['up'] ?? false),
			'defaultDns' => $row?->getDefaultDns(),
			'defaultAllowedIps' => $row?->getDefaultAllowedIps()
				?? PeerPresets::PRESET_FIELD['allowed_ips'],
			'defaultKeepalive' => $row?->getDefaultKeepalive()
				?? PeerPresets::PRESET_FIELD['persistent_keepalive'],
			'notes' => $health['ok'] ? [] : ['wg-sync: ' . $health['error']],
		];
	}

	public function getConfiguration(int $peerId): array
	{
		$peer = $this->peers->findByEngineId($peerId);
		if ($peer === null) {
			return [
				'ok' => false,
				'http_code' => 404,
				'body' => false,
				'error' => 'No stored peer for id ' . $peerId,
				'is_json' => false,
			];
		}
		try {
			$body = $this->confBuilder->buildForPeer($peer);
		} catch (Throwable $e) {
			return [
				'ok' => false,
				'http_code' => 422,
				'body' => false,
				'error' => $e->getMessage(),
				'is_json' => false,
			];
		}
		return ['ok' => true, 'http_code' => 200, 'body' => $body, 'error' => '', 'is_json' => false];
	}

	public function formatConfigurationBody(string $body, bool $isJson): array
	{
		if ($isJson) {
			$decoded = json_decode($body, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		return ['configuration' => $body];
	}

	// --- write ------------------------------------------------------------

	public function create(array $fields): array
	{
		if ($refusal = $this->refuseUnsupported($fields)) {
			return $refusal;
		}
		$name = trim((string) ($fields['name'] ?? ''));
		if ($name === '') {
			return $this->error(400, 'Peer name is required');
		}

		try {
			$keys = WireGuardKeys::generate();
			$now = new DateTime('now', new DateTimeZone('UTC'));

			$peer = new Peer();
			$peer->setUuid(PeerStoreService::newUuid());
			$peer->setPublicKey($keys['public']);
			$peer->setWgEasyId(null);
			$peer->setName(mb_substr($name, 0, 255));
			$peer->setEnabled(true);
			$peer->setIpv4($this->ipam->allocate());
			$peer->setHasAmnezia(false);
			$peer->setCreatedAt($now);
			$peer->setUpdatedAt($now);
			$this->applyTunnelFields($peer, $fields);
			$peer = $this->peers->insert($peer);

			$secret = new PeerSecret();
			$secret->setPeerId((int) $peer->getId());
			$secret->setPrivateKeyEnc($this->crypto->encrypt($keys['private']));
			$secret->setPskEnc(null);
			$this->secrets->save($secret, false);
		} catch (Throwable $e) {
			return $this->error(500, 'Could not create peer: ' . $e->getMessage());
		}

		$sync = $this->syncToSidecar();
		if (!$sync['ok']) {
			return array_merge($sync, ['clientId' => (int) $peer->getId()]);
		}
		return ['ok' => true, 'http_code' => 201, 'clientId' => (int) $peer->getId()];
	}

	public function update(int $peerId, array $fields): array
	{
		if ($refusal = $this->refuseUnsupported($fields)) {
			return $refusal;
		}
		$peer = $this->peers->findByEngineId($peerId);
		if ($peer === null) {
			return $this->error(404, 'No stored peer for id ' . $peerId);
		}
		if ($peer->getHasAmnezia()) {
			return $this->error(
				422,
				'Peer ' . $peer->getUuid() . ' carries Amnezia obfuscation, which the native '
				. 'engine cannot honour — clear those fields on wg-easy before switching',
				self::ERR_AMNEZIA
			);
		}

		try {
			if (isset($fields['name']) && trim((string) $fields['name']) !== '') {
				$peer->setName(mb_substr(trim((string) $fields['name']), 0, 255));
			}
			if (array_key_exists('enabled', $fields)) {
				$peer->setEnabled((bool) $fields['enabled']);
			}
			$this->applyTunnelFields($peer, $fields);
			$peer->setUpdatedAt(new DateTime('now', new DateTimeZone('UTC')));
			$this->peers->update($peer);
		} catch (Throwable $e) {
			return $this->error(500, 'Could not update peer: ' . $e->getMessage());
		}

		return $this->syncToSidecar();
	}

	public function delete(int $peerId): array
	{
		$peer = $this->peers->findByEngineId($peerId);
		if ($peer === null) {
			return $this->error(404, 'No stored peer for id ' . $peerId);
		}
		try {
			$this->secrets->deleteByPeerId((int) $peer->getId());
			$this->peers->delete($peer);
		} catch (Throwable $e) {
			return $this->error(500, 'Could not delete peer: ' . $e->getMessage());
		}
		return $this->syncToSidecar();
	}

	public function enable(int $peerId): array
	{
		return $this->toggle($peerId, true);
	}

	public function disable(int $peerId): array
	{
		return $this->toggle($peerId, false);
	}

	// --- one-time links ---------------------------------------------------

	public function generateOneTimeLink(int $peerId): array
	{
		return $this->otl->mintForEngineId($peerId);
	}

	public function redeemOneTimeLink(string $token): array
	{
		// Already the interface envelope, plus a `filename` the controller uses
		// for the download name when it goes through the engine rather than
		// calling NcOtlService directly.
		return $this->otl->redeem($token);
	}

	// --- sidecar ----------------------------------------------------------

	/**
	 * Push the whole peer set to wg-sync.
	 *
	 * The full set every time, not a delta: `wg syncconf` is declarative, and
	 * a delta protocol would drift the moment one apply failed.
	 *
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function syncToSidecar(): array
	{
		$row = $this->server->load();
		try {
			$privateKey = $this->serverKeys->get();
		} catch (Throwable $e) {
			return $this->error(500, 'Server key is unreadable: ' . $e->getMessage(), self::ERR_NO_SERVER_KEY);
		}
		if ($privateKey === null) {
			return $this->error(
				503,
				'No WireGuard interface key stored — run occ nc_wireguard:set-server-key first',
				self::ERR_NO_SERVER_KEY
			);
		}

		$payload = [
			'private_key' => $privateKey,
			'listen_port' => (int) ($row?->getPort() ?? PeerConfBuilder::DEFAULT_PORT),
			'address' => [$this->interfaceAddress($row)],
			'mtu' => (int) ($row?->getMtu() ?? PeerConfBuilder::DEFAULT_MTU),
			'ipv4_only' => $row === null ? true : (bool) $row->getIpv4Only(),
			'peers' => $this->peerPayload($row),
		];

		$result = $this->wgSync->apply($payload);
		if (!$result['ok']) {
			$this->logger->error('nc_wireguard native engine: wg-sync apply failed: {err}', [
				'err' => $result['error'],
			]);
			return $this->error(
				$result['http_code'] >= 400 ? $result['http_code'] : 502,
				'wg-sync apply failed: ' . $result['error']
			);
		}
		return ['ok' => true, 'http_code' => 200];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function peerPayload(?ServerConfig $row): array
	{
		$out = [];
		foreach ($this->peers->findAll() as $peer) {
			if ($peer->getHasAmnezia()) {
				// Refused, not dropped: the operator needs to know this peer is
				// deliberately absent from the interface.
				$this->logger->error(
					'nc_wireguard native engine: excluding peer {uuid} ({name}) — Amnezia '
					. 'obfuscation has no kernel-WireGuard equivalent',
					['uuid' => $peer->getUuid(), 'name' => $peer->getName()]
				);
				continue;
			}
			$allowed = $this->tunnelRouteFor($peer);
			if ($allowed === []) {
				$this->logger->error(
					'nc_wireguard native engine: excluding peer {uuid} — no usable IPv4 address',
					['uuid' => $peer->getUuid()]
				);
				continue;
			}

			$entry = [
				'name' => (string) $peer->getName(),
				'public_key' => (string) $peer->getPublicKey(),
				'allowed_ips' => $allowed,
				'enabled' => (bool) $peer->getEnabled(),
			];
			$keepalive = $peer->getPersistentKeepalive() ?? $row?->getDefaultKeepalive();
			if (is_int($keepalive) && $keepalive > 0) {
				$entry['persistent_keepalive'] = $keepalive;
			}
			$psk = $this->pskFor($peer);
			if ($psk !== null) {
				$entry['preshared_key'] = $psk;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * What the *server* routes to this peer — its tunnel address, not the
	 * peer's own AllowedIPs (which describe traffic in the other direction).
	 *
	 * @return list<string>
	 */
	private function tunnelRouteFor(Peer $peer): array
	{
		$host = PeerIpam::hostOf((string) ($peer->getIpv4() ?? ''));
		return $host === null ? [] : [$host . '/32'];
	}

	private function pskFor(Peer $peer): ?string
	{
		$secret = $this->secrets->findByPeerId((int) $peer->getId());
		if ($secret === null) {
			return null;
		}
		try {
			return $this->crypto->decryptOptional($secret->getPskEnc());
		} catch (Throwable) {
			// Never log the value, and never fall back to the ciphertext — a
			// wrong PSK is a peer that handshakes and then drops every packet.
			$this->logger->error('nc_wireguard native engine: unreadable PSK for {uuid}', [
				'uuid' => $peer->getUuid(),
			]);
			return null;
		}
	}

	private function interfaceAddress(?ServerConfig $row): string
	{
		$cidr = $row?->getCidr();
		$cidr = is_string($cidr) && trim($cidr) !== '' ? trim($cidr) : PeerIpam::DEFAULT_CIDR;
		$prefix = explode('/', $cidr, 2)[1] ?? '24';
		return $this->ipam->serverAddress($cidr) . '/' . $prefix;
	}

	// --- helpers ----------------------------------------------------------

	private function toggle(int $peerId, bool $enabled): array
	{
		return $this->update($peerId, ['enabled' => $enabled]);
	}

	/**
	 * Reject the fields this engine cannot honour, before anything is written.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{ok: bool, http_code: int, error: string, code: string}|null
	 */
	private function refuseUnsupported(array $fields): ?array
	{
		$lowered = [];
		foreach ($fields as $key => $value) {
			if (is_string($key)) {
				$lowered[strtolower($key)] = $value;
			}
		}

		foreach (['jc', 'jmin', 'jmax', 'i1', 'i2', 'i3', 'i4', 'i5'] as $key) {
			$value = $lowered[$key] ?? null;
			if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
				return $this->error(
					422,
					'Amnezia obfuscation (' . $key . ') is not supported by the native engine. '
					. 'The field is refused rather than dropped: a silently ignored knob '
					. 'produces a peer that looks configured and cannot connect.',
					self::ERR_AMNEZIA
				);
			}
		}

		$ipv6 = $lowered['ipv6address'] ?? $lowered['ipv6'] ?? null;
		if (is_string($ipv6) && trim($ipv6) !== '') {
			return $this->error(
				422,
				'The native engine is IPv4-only; no IPv6 address is assigned.',
				self::ERR_IPV6
			);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function applyTunnelFields(Peer $peer, array $fields): void
	{
		$row = $this->server->load();
		$ipv4Only = $row === null ? true : (bool) $row->getIpv4Only();

		if (array_key_exists('allowedIps', $fields)) {
			$peer->setAllowedIps($this->listOrNull($fields['allowedIps'], $ipv4Only));
		}
		if (array_key_exists('dns', $fields)) {
			$peer->setDns($this->listOrNull($fields['dns'], $ipv4Only));
		}
		if (array_key_exists('mtu', $fields) && is_numeric($fields['mtu'])) {
			$peer->setMtu((int) $fields['mtu']);
		}
		if (array_key_exists('persistentKeepalive', $fields) && is_numeric($fields['persistentKeepalive'])) {
			$peer->setPersistentKeepalive((int) $fields['persistentKeepalive']);
		}
		if (array_key_exists('serverEndpoint', $fields)) {
			$value = is_string($fields['serverEndpoint']) ? trim($fields['serverEndpoint']) : '';
			$peer->setServerEndpoint($value === '' ? null : $value);
		}
		if (array_key_exists('serverAllowedIps', $fields)) {
			$peer->setServerAllowedIps($this->listOrNull($fields['serverAllowedIps'], $ipv4Only));
		}
		if (array_key_exists('firewallIps', $fields)) {
			$peer->setFirewallIps($this->listOrNull($fields['firewallIps'], $ipv4Only));
		}

		// A peer with no per-peer routes falls back to the deployment default
		// and then the Field preset, so a freshly created peer is usable.
		if (($peer->getAllowedIps() ?? '') === '') {
			$peer->setAllowedIps(
				$row?->getDefaultAllowedIps() ?? PeerPresets::PRESET_FIELD['allowed_ips']
			);
		}
		if ($peer->getPersistentKeepalive() === null) {
			$peer->setPersistentKeepalive(
				$row?->getDefaultKeepalive() ?? PeerPresets::PRESET_FIELD['persistent_keepalive']
			);
		}
	}

	private function listOrNull(mixed $value, bool $ipv4Only): ?string
	{
		if (is_array($value)) {
			$value = implode(', ', array_filter($value, 'is_string'));
		}
		if (!is_string($value)) {
			return null;
		}
		$parts = [];
		foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $entry) {
			$entry = trim($entry);
			if ($entry === '' || ($ipv4Only && str_contains($entry, ':'))) {
				continue;
			}
			$parts[] = $entry;
		}
		return $parts === [] ? null : implode(', ', $parts);
	}

	/**
	 * @param array<string, mixed>|null $stats
	 * @return array<string, mixed> wg-easy-shaped so the existing frontend and
	 *                              the metrics poller need no branching
	 */
	private function toEngineShape(Peer $peer, ?array $stats): array
	{
		return [
			'id' => (int) $peer->getId(),
			'uuid' => (string) $peer->getUuid(),
			'name' => (string) $peer->getName(),
			'enabled' => (bool) $peer->getEnabled(),
			'publicKey' => (string) $peer->getPublicKey(),
			'ipv4Address' => PeerIpam::hostOf((string) ($peer->getIpv4() ?? '')),
			'ipv6Address' => null,
			'allowedIps' => $this->splitList($peer->getAllowedIps()),
			'dns' => $this->splitList($peer->getDns()),
			'mtu' => $peer->getMtu(),
			'persistentKeepalive' => $peer->getPersistentKeepalive(),
			'serverEndpoint' => $peer->getServerEndpoint(),
			'serverAllowedIps' => $this->splitList($peer->getServerAllowedIps()),
			'firewallIps' => $this->splitList($peer->getFirewallIps()),
			'hasAmnezia' => (bool) $peer->getHasAmnezia(),
			'transferRx' => (int) ($stats['transfer_rx'] ?? 0),
			'transferTx' => (int) ($stats['transfer_tx'] ?? 0),
			'endpoint' => $stats['endpoint'] ?? null,
			'latestHandshakeAt' => $stats['latest_handshake'] ?? null,
			'connected' => (bool) ($stats['connected'] ?? false),
		];
	}

	/** @return list<string> */
	private function splitList(?string $value): array
	{
		if ($value === null || trim($value) === '') {
			return [];
		}
		$parts = [];
		foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $entry) {
			if (trim($entry) !== '') {
				$parts[] = trim($entry);
			}
		}
		return $parts;
	}

	/**
	 * @return array{ok: false, http_code: int, error: string, code: string}
	 */
	private function error(int $httpCode, string $message, string $code = ''): array
	{
		return ['ok' => false, 'http_code' => $httpCode, 'error' => $message, 'code' => $code];
	}
}
