<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit;

use OCA\NcWireguard\Service\ConnectionStateMachine;
use OCA\NcWireguard\Service\WireGuardEngineInterface;

/**
 * In-memory engine for tests — no HTTP, no wg-easy container.
 *
 * Mirrors the wg-easy result envelopes closely enough that controller tests can
 * exercise the real success and failure branches. Also doubles as the reference
 * for what a second engine implementation has to honour.
 */
final class FakeWireGuardEngine implements WireGuardEngineInterface
{
	/** @var array<int, array<string, mixed>> */
	private array $peers = [];

	private int $nextId = 1;

	/** @var array<string, int> token => peer id */
	private array $oneTimeLinks = [];

	private bool $reachable = true;

	/**
	 * @param list<array<string, mixed>> $peers
	 */
	public function __construct(array $peers = [])
	{
		foreach ($peers as $peer) {
			$id = isset($peer['id']) && is_numeric($peer['id']) ? (int) $peer['id'] : $this->nextId;
			$this->peers[$id] = ['id' => $id] + $peer;
			$this->nextId = max($this->nextId, $id + 1);
		}
	}

	public function setReachable(bool $reachable): void
	{
		$this->reachable = $reachable;
	}

	/**
	 * wg-easy's list endpoint strips key material; only get-one returns it. The
	 * fake mirrors that so importers are forced through the same two-step read.
	 */
	public function listPeers(): ?array
	{
		if (!$this->reachable) {
			return null;
		}
		$peers = [];
		foreach ($this->peers as $peer) {
			unset($peer['privateKey'], $peer['private_key'], $peer['preSharedKey'], $peer['presharedKey']);
			$peers[] = $peer;
		}
		return $peers;
	}

	public function getPeer(int $peerId): ?array
	{
		if (!$this->reachable) {
			return null;
		}
		return $this->peers[$peerId] ?? null;
	}

	public function create(array $fields): array
	{
		if (!$this->reachable) {
			return ['ok' => false, 'http_code' => 502, 'error' => 'engine unreachable'];
		}
		$id = $this->nextId++;
		$this->peers[$id] = [
			'id' => $id,
			'name' => (string) $fields['name'],
			'enabled' => true,
			'expiresAt' => $fields['expiresAt'] ?? null,
			'publicKey' => 'pk-' . $id,
			'ipv4Address' => '10.8.0.' . $id,
			'endpoint' => null,
			'latestHandshakeAt' => null,
			'transferRx' => 0,
			'transferTx' => 0,
		];
		return [
			'ok' => true,
			'http_code' => 201,
			'clientId' => $id,
			'body' => $this->peers[$id],
		];
	}

	public function update(int $peerId, array $fields): array
	{
		if (!isset($this->peers[$peerId])) {
			return ['ok' => false, 'http_code' => 404, 'error' => 'client not found'];
		}
		$this->peers[$peerId] = array_merge($this->peers[$peerId], $fields);
		return ['ok' => true, 'http_code' => 200, 'body' => $this->peers[$peerId]];
	}

	public function delete(int $peerId): array
	{
		if (!isset($this->peers[$peerId])) {
			return ['ok' => false, 'http_code' => 404, 'error' => 'client not found'];
		}
		unset($this->peers[$peerId]);
		return ['ok' => true, 'http_code' => 200];
	}

	public function enable(int $peerId): array
	{
		return $this->setEnabled($peerId, true);
	}

	public function disable(int $peerId): array
	{
		return $this->setEnabled($peerId, false);
	}

	public function getConfiguration(int $peerId): array
	{
		if (!isset($this->peers[$peerId])) {
			return [
				'ok' => false,
				'http_code' => 404,
				'body' => false,
				'error' => 'client not found',
				'is_json' => false,
			];
		}
		return [
			'ok' => true,
			'http_code' => 200,
			'body' => $this->configBodyFor($peerId),
			'error' => '',
			'is_json' => false,
		];
	}

	public function formatConfigurationBody(string $body, bool $isJson): array
	{
		if ($isJson) {
			$data = json_decode($body, true);
			if (is_array($data)) {
				return $data;
			}
		}
		return ['configuration' => $body];
	}

	public function getRuntimeStats(): array
	{
		if (!$this->reachable) {
			return [];
		}
		$fsm = new ConnectionStateMachine();
		$stats = [];
		foreach ($this->peers as $id => $peer) {
			$publicKey = $peer['publicKey'] ?? null;
			if (!is_string($publicKey) || $publicKey === '') {
				continue;
			}
			$handshake = $peer['latestHandshakeAt'] ?? null;
			$stats[$publicKey] = [
				'public_key' => $publicKey,
				'peer_id' => $id,
				'name' => (string) ($peer['name'] ?? ''),
				'transfer_rx' => (int) ($peer['transferRx'] ?? 0),
				'transfer_tx' => (int) ($peer['transferTx'] ?? 0),
				'endpoint' => is_string($peer['endpoint'] ?? null) ? $peer['endpoint'] : null,
				'latest_handshake' => is_string($handshake) && $handshake !== '' ? $handshake : null,
				'connected' => $fsm->isConnected($peer),
			];
		}
		return $stats;
	}

	public function getServerInfo(): array
	{
		return [
			'ok' => true,
			'unavailable' => false,
			'host' => '127.0.0.1',
			'port' => 51820,
			'mtu' => 1420,
			'ipv4Cidr' => '10.8.0.0/24',
			'ipv6Cidr' => null,
			'interfaceName' => 'wg0',
			'notes' => [],
		];
	}

	public function generateOneTimeLink(int $peerId): array
	{
		if (!isset($this->peers[$peerId])) {
			return ['ok' => false, 'http_code' => 404, 'error' => 'client not found'];
		}
		$token = 'otl-' . $peerId . '-' . count($this->oneTimeLinks);
		$this->oneTimeLinks[$token] = $peerId;
		return [
			'ok' => true,
			'http_code' => 200,
			'oneTimeLink' => $token,
			'redeemPath' => '/cnf/' . $token,
			'expiresAt' => '2026-01-01T00:00:00Z',
		];
	}

	public function redeemOneTimeLink(string $token): array
	{
		$peerId = $this->oneTimeLinks[$token] ?? null;
		if ($peerId === null) {
			return [
				'ok' => false,
				'http_code' => 404,
				'body' => false,
				'error' => 'invalid token',
				'content_type' => '',
			];
		}
		// Engine-side single use; NC also tracks redeems independently.
		unset($this->oneTimeLinks[$token]);
		return [
			'ok' => true,
			'http_code' => 200,
			'body' => $this->configBodyFor($peerId),
			'error' => '',
			'content_type' => 'text/plain',
		];
	}

	/**
	 * @return array{ok: bool, http_code: int, error?: string}
	 */
	private function setEnabled(int $peerId, bool $enabled): array
	{
		if (!isset($this->peers[$peerId])) {
			return ['ok' => false, 'http_code' => 404, 'error' => 'client not found'];
		}
		$this->peers[$peerId]['enabled'] = $enabled;
		return ['ok' => true, 'http_code' => 200];
	}

	private function configBodyFor(int $peerId): string
	{
		$peer = $this->peers[$peerId];
		return "[Interface]\nAddress = " . (string) ($peer['ipv4Address'] ?? '') . "/24\n";
	}
}
