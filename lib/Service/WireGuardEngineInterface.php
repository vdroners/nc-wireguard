<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

/**
 * Engine-agnostic WireGuard peer surface (v2.3).
 *
 * Everything NC needs from a WireGuard backend — peer CRUD, config download,
 * runtime counters, server info. `WgEasyEngine` is the production
 * implementation and simply wraps `WgEasyClient`; a native `wg`-driven engine
 * can be dropped in later without touching controllers or the poller.
 *
 * Result arrays keep the `{ok, http_code, error?, code?, body?}` envelope that
 * the controllers already branch on, so a non-HTTP engine must synthesise
 * plausible status codes (200 / 404 / 502).
 */
interface WireGuardEngineInterface
{
	/**
	 * All peers, or null when the engine is unreachable.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public function listPeers(): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function getPeer(int $peerId): ?array;

	/**
	 * @param array{name: string, expiresAt?: string|null} $fields
	 * @return array{ok: bool, http_code: int, clientId?: int|null, error?: string, code?: string, body?: mixed}
	 */
	public function create(array $fields): array;

	/**
	 * @param array<string, mixed> $fields
	 * @return array{ok: bool, http_code: int, error?: string, code?: string, body?: mixed}
	 */
	public function update(int $peerId, array $fields): array;

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function delete(int $peerId): array;

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function enable(int $peerId): array;

	/**
	 * @return array{ok: bool, http_code: int, error?: string, code?: string}
	 */
	public function disable(int $peerId): array;

	/**
	 * Raw peer configuration payload (the `.conf` body, or JSON on some engines).
	 *
	 * @return array{ok: bool, http_code: int, body: string|false, error: string, is_json: bool}
	 */
	public function getConfiguration(int $peerId): array;

	/**
	 * Turn a `getConfiguration()` body into the JSON the frontend consumes.
	 *
	 * @return array<string, mixed>
	 */
	public function formatConfigurationBody(string $body, bool $isJson): array;

	/**
	 * Live transfer counters keyed by WireGuard public key.
	 *
	 * The public key is the only peer identifier every engine shares — wg-easy
	 * integer ids do not exist on a native `wg` backend. `peer_id` is carried
	 * along for the current integer-keyed metrics tables.
	 *
	 * @return array<string, array{
	 *   public_key: string,
	 *   peer_id?: int,
	 *   name?: string,
	 *   transfer_rx: int,
	 *   transfer_tx: int,
	 *   endpoint: string|null,
	 *   latest_handshake: string|null,
	 *   connected?: bool
	 * }>
	 */
	public function getRuntimeStats(): array;

	/**
	 * Read-only server/interface defaults for the System tab.
	 *
	 * @return array<string, mixed>
	 */
	public function getServerInfo(): array;

	/**
	 * @return array{ok: bool, http_code: int, oneTimeLink?: string|null, redeemPath?: string|null, expiresAt?: string|null, error?: string, code?: string}
	 */
	public function generateOneTimeLink(int $peerId): array;

	/**
	 * @return array{ok: bool, http_code: int, body: string|false, error: string, content_type: string}
	 */
	public function redeemOneTimeLink(string $token): array;
}
