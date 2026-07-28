<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Default engine — a thin adapter over the existing wg-easy REST client.
 *
 * Deliberately does no work of its own beyond renaming methods to the
 * engine-agnostic vocabulary and normalising `getRuntimeStats()` to be keyed by
 * public key. Behaviour on the wire is identical to calling `WgEasyClient`
 * directly, so swapping the DI alias is the only production change.
 */
class WgEasyEngine implements WireGuardEngineInterface
{
	public function __construct(
		private WgEasyClient $client,
		private ConnectionStateMachine $fsm,
	) {
	}

	public function listPeers(): ?array
	{
		return $this->client->getClients();
	}

	public function getPeer(int $peerId): ?array
	{
		return $this->client->getClient($peerId);
	}

	public function create(array $fields): array
	{
		return $this->client->createClient($fields);
	}

	public function update(int $peerId, array $fields): array
	{
		return $this->client->updateClient($peerId, $fields);
	}

	public function delete(int $peerId): array
	{
		return $this->client->deleteClient($peerId);
	}

	public function enable(int $peerId): array
	{
		return $this->client->enableClient($peerId);
	}

	public function disable(int $peerId): array
	{
		return $this->client->disableClient($peerId);
	}

	public function getConfiguration(int $peerId): array
	{
		return $this->client->getClientConfiguration($peerId);
	}

	public function formatConfigurationBody(string $body, bool $isJson): array
	{
		return $this->client->formatConfigurationBody($body, $isJson);
	}

	public function getRuntimeStats(): array
	{
		$peers = $this->client->getClients();
		if ($peers === null) {
			return [];
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$stats = [];
		foreach ($peers as $peer) {
			if (!is_array($peer)) {
				continue;
			}
			$publicKey = $peer['publicKey'] ?? $peer['public_key'] ?? null;
			if (!is_string($publicKey) || $publicKey === '') {
				// Without a public key there is no engine-agnostic identity to
				// key on; the integer-id metrics path still covers this peer.
				continue;
			}

			$entry = [
				'public_key' => $publicKey,
				'transfer_rx' => (int) ($peer['transferRx'] ?? $peer['transfer_rx'] ?? 0),
				'transfer_tx' => (int) ($peer['transferTx'] ?? $peer['transfer_tx'] ?? 0),
				'endpoint' => isset($peer['endpoint']) && is_string($peer['endpoint'])
					? $peer['endpoint']
					: null,
				'latest_handshake' => $this->handshakeOf($peer),
				'connected' => $this->fsm->isConnected($peer, $now),
			];

			$peerId = $peer['id'] ?? $peer['peer_id'] ?? null;
			if (is_numeric($peerId)) {
				$entry['peer_id'] = (int) $peerId;
			}
			$name = $peer['name'] ?? null;
			if (is_string($name)) {
				$entry['name'] = $name;
			}

			$stats[$publicKey] = $entry;
		}

		return $stats;
	}

	public function getServerInfo(): array
	{
		return $this->client->getServerDefaults();
	}

	public function generateOneTimeLink(int $peerId): array
	{
		return $this->client->generateOneTimeLink($peerId);
	}

	public function redeemOneTimeLink(string $token): array
	{
		return $this->client->redeemOneTimeLink($token);
	}

	/**
	 * @param array<string, mixed> $peer
	 */
	private function handshakeOf(array $peer): ?string
	{
		$raw = $peer['latestHandshakeAt'] ?? $peer['latest_handshake'] ?? null;
		return is_string($raw) && $raw !== '' ? $raw : null;
	}
}
