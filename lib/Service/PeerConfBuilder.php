<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCA\NcWireguard\Db\PeerSecretMapper;
use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Db\ServerConfigMapper;

/**
 * Builds a WireGuard `.conf` from the NC peer store instead of asking the
 * engine for one (P4).
 *
 * Field precedence is always peer → server row → preset. That order matters:
 * an operator who edited one peer's AllowedIPs must not have it silently
 * replaced by the deployment default, but a peer imported from wg-easy with
 * `keepalive = 0` should still pick up the server's 25 s once the operator
 * sets it there.
 *
 * IPv6 is filtered out entirely while `nc_wg_server.ipv4_only` is set, which
 * is the P3 policy: the native tunnel assigns no IPv6, so emitting `::/0`
 * would black-hole a peer's IPv6 traffic into a tunnel that cannot carry it.
 */
class PeerConfBuilder
{
	/** wg-easy's default when the server row says nothing. */
	public const DEFAULT_PORT = 51820;

	public const DEFAULT_MTU = 1420;

	public function __construct(
		private PeerMapper $peers,
		private PeerSecretMapper $secrets,
		private ServerConfigMapper $server,
		private PeerSecretCrypto $crypto,
	) {
	}

	/**
	 * @throws PeerConfException when the peer is unknown or unbuildable
	 */
	public function buildForUuid(string $uuid): string
	{
		$peer = $this->peers->findByUuid($uuid);
		if ($peer === null) {
			throw new PeerConfException('No stored peer with uuid ' . $uuid);
		}
		return $this->buildForPeer($peer);
	}

	/**
	 * @throws PeerConfException
	 */
	public function buildForPeer(Peer $peer): string
	{
		$secret = $this->secrets->findByPeerId((int) $peer->getId());
		if ($secret === null) {
			throw new PeerConfException(
				'Peer ' . $peer->getUuid() . ' has no stored key material — '
				. 'run occ nc_wireguard:import-peers before building configs'
			);
		}
		return $this->render(
			$peer,
			$this->crypto->decrypt((string) $secret->getPrivateKeyEnc()),
			$this->crypto->decryptOptional($secret->getPskEnc()),
			$this->server->load()
		);
	}

	/**
	 * Pure renderer — no database access, so the preset behaviour is testable
	 * without a Nextcloud container.
	 *
	 * @throws PeerConfException
	 */
	public function render(
		Peer $peer,
		string $privateKey,
		?string $psk,
		?ServerConfig $server,
	): string {
		if (trim($privateKey) === '') {
			throw new PeerConfException('Refusing to build a config without a private key');
		}

		$ipv4Only = $server === null ? true : (bool) $server->getIpv4Only();
		$serverPublicKey = trim((string) ($server?->getServerPublicKey() ?? ''));
		if ($serverPublicKey === '') {
			throw new PeerConfException(
				'nc_wg_server.server_public_key is empty — the tunnel peer section '
				. 'cannot be built until the server key is recorded'
			);
		}

		$address = $this->addressOf($peer);
		$endpoint = $this->endpointOf($peer, $server);
		$allowedIps = $this->allowedIpsOf($peer, $server, $ipv4Only);

		$interface = [
			'PrivateKey' => trim($privateKey),
			'Address' => $address,
		];
		$dns = $this->firstText([$peer->getDns(), $server?->getDefaultDns()]);
		if ($dns !== null) {
			$interface['DNS'] = $this->joinList($dns, $ipv4Only);
		}
		$mtu = $this->firstNumber([$peer->getMtu(), $server?->getMtu()]) ?? self::DEFAULT_MTU;
		$interface['MTU'] = (string) $mtu;

		$peerSection = ['PublicKey' => $serverPublicKey];
		if ($psk !== null && trim($psk) !== '') {
			$peerSection['PresharedKey'] = trim($psk);
		}
		$peerSection['AllowedIPs'] = $allowedIps;
		$peerSection['Endpoint'] = $endpoint;
		$keepalive = $this->firstNumber([
			$peer->getPersistentKeepalive(),
			$server?->getDefaultKeepalive(),
		]);
		// 0 is wg-easy's "off"; omitting the line means the same thing and keeps
		// the file readable, so only a positive value is written.
		if ($keepalive !== null && $keepalive > 0) {
			$peerSection['PersistentKeepalive'] = (string) $keepalive;
		}

		return $this->renderSections($interface, $peerSection);
	}

	/**
	 * Download filename for a peer, safe for a Content-Disposition header.
	 */
	public function filenameFor(Peer $peer): string
	{
		$slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $peer->getName()) ?? '';
		$slug = trim($slug, '-');
		if ($slug === '') {
			$slug = 'peer';
		}
		return mb_substr($slug, 0, 48) . '.conf';
	}

	/**
	 * @param array<string, string> $interface
	 * @param array<string, string> $peer
	 */
	private function renderSections(array $interface, array $peer): string
	{
		$lines = ['[Interface]'];
		foreach ($interface as $key => $value) {
			$lines[] = $key . ' = ' . $value;
		}
		$lines[] = '';
		$lines[] = '[Peer]';
		foreach ($peer as $key => $value) {
			$lines[] = $key . ' = ' . $value;
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * @throws PeerConfException
	 */
	private function addressOf(Peer $peer): string
	{
		$raw = trim((string) ($peer->getIpv4() ?? ''));
		if ($raw === '') {
			throw new PeerConfException(
				'Peer ' . $peer->getUuid() . ' has no tunnel address — let IPAM assign one first'
			);
		}
		$host = PeerIpam::hostOf($raw);
		if ($host === null) {
			throw new PeerConfException(
				'Peer ' . $peer->getUuid() . ' has an unusable tunnel address: ' . $raw
			);
		}
		// Stored as /32 already; normalising here means a hand-edited /24 does
		// not turn the peer into a router for the whole pool. IPv6 is never
		// added: `ipv4_only` is the P3 policy and there is no v6 pool to draw
		// from even when it is cleared.
		return $host . '/32';
	}

	/**
	 * @throws PeerConfException
	 */
	private function endpointOf(Peer $peer, ?ServerConfig $server): string
	{
		$port = $this->firstNumber([$server?->getPort()]) ?? self::DEFAULT_PORT;

		$override = trim((string) ($peer->getServerEndpoint() ?? ''));
		if ($override !== '') {
			return $this->hasPort($override) ? $override : $override . ':' . $port;
		}

		$host = trim((string) ($server?->getHost() ?? ''));
		if ($host === '') {
			throw new PeerConfException(
				'No endpoint for peer ' . $peer->getUuid() . ' — set nc_wg_server.host '
				. 'or the peer\'s serverEndpoint override'
			);
		}
		return $this->hasPort($host) ? $host : $host . ':' . $port;
	}

	/**
	 * `host:port`, `[v6]:port`, and a bare hostname all have to be told apart.
	 */
	private function hasPort(string $endpoint): bool
	{
		if (str_starts_with($endpoint, '[')) {
			return (bool) preg_match('/]\s*:\d+$/', $endpoint);
		}
		return substr_count($endpoint, ':') === 1 && (bool) preg_match('/:\d+$/', $endpoint);
	}

	/**
	 * @throws PeerConfException
	 */
	private function allowedIpsOf(Peer $peer, ?ServerConfig $server, bool $ipv4Only): string
	{
		$raw = $this->firstText([
			$peer->getAllowedIps(),
			$server?->getDefaultAllowedIps(),
			PeerPresets::PRESET_FIELD['allowed_ips'],
		]);
		$joined = $this->joinList((string) $raw, $ipv4Only);
		if ($joined === '') {
			throw new PeerConfException(
				'Peer ' . $peer->getUuid() . ' has no IPv4 AllowedIPs left after the '
				. 'ipv4_only filter — a config with an empty AllowedIPs routes nothing'
			);
		}
		return $joined;
	}

	/**
	 * Normalise a comma/space separated list, dropping IPv6 entries under the
	 * ipv4-only policy so no `::/0` reaches a peer file.
	 */
	private function joinList(string $raw, bool $ipv4Only): string
	{
		$parts = [];
		foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $entry) {
			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}
			if ($ipv4Only && str_contains($entry, ':')) {
				continue;
			}
			$parts[] = $entry;
		}
		return implode(', ', $parts);
	}

	/**
	 * @param list<mixed> $candidates
	 */
	private function firstText(array $candidates): ?string
	{
		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return trim($candidate);
			}
		}
		return null;
	}

	/**
	 * @param list<mixed> $candidates
	 */
	private function firstNumber(array $candidates): ?int
	{
		foreach ($candidates as $candidate) {
			if (is_int($candidate)) {
				return $candidate;
			}
			if (is_string($candidate) && preg_match('/^\d+$/', trim($candidate)) === 1) {
				return (int) trim($candidate);
			}
		}
		return null;
	}
}
