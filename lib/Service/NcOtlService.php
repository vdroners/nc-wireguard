<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Db\Peer;
use OCA\NcWireguard\Db\PeerMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nextcloud-minted one-time config links (P4), used when
 * `AppSettings::getOtlSource() === 'nc'`.
 *
 * Tokens live in appconfig rather than a table: they are single-use, expire in
 * five minutes, and are minted by an admin one at a time, so the volume never
 * justifies a migration. The stored value maps token → peer uuid + expiry; the
 * `.conf` itself is rendered on redeem so a rotated key or an edited AllowedIPs
 * is reflected in whatever the field user finally downloads.
 *
 * Redeem is deliberately destructive: the token is dropped before the body is
 * returned, so a replay gets `410` from the caller's tracker rather than a
 * second copy of the key material.
 */
class NcOtlService
{
	private const CONFIG_KEY = 'nc_otl_tokens';

	/** Matches wg-easy's ~5 minute window so operator habits carry over. */
	public const TTL_SECONDS = 300;

	/** Bounded so a burst of mints cannot grow the appconfig row without limit. */
	private const MAX_TOKENS = 100;

	public const ERR_UNKNOWN = 'unknown_token';
	public const ERR_EXPIRED = 'expired_token';

	public function __construct(
		private IConfig $config,
		private PeerMapper $peers,
		private PeerConfBuilder $confBuilder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Mint a token for the peer behind an engine-facing integer id.
	 *
	 * @return array{ok: bool, http_code: int, oneTimeLink?: string, redeemPath?: string, expiresAt?: string, error?: string, code?: string}
	 */
	public function mintForEngineId(int $engineId): array
	{
		$peer = $this->peers->findByEngineId($engineId);
		if ($peer === null) {
			return [
				'ok' => false,
				'http_code' => 404,
				'error' => 'No stored peer for id ' . $engineId
					. ' — run occ nc_wireguard:import-peers first',
				'code' => self::ERR_UNKNOWN,
			];
		}
		return $this->mint($peer);
	}

	/**
	 * @return array{ok: bool, http_code: int, oneTimeLink?: string, redeemPath?: string, expiresAt?: string, error?: string, code?: string}
	 */
	public function mint(Peer $peer): array
	{
		// Fail before handing out a link rather than after the field user has
		// already tapped it: a token that cannot render is worse than no token.
		try {
			$this->confBuilder->buildForPeer($peer);
		} catch (Throwable $e) {
			return [
				'ok' => false,
				'http_code' => 422,
				'error' => $e->getMessage(),
			];
		}

		$token = self::newToken();
		$expiresAt = time() + self::TTL_SECONDS;

		$state = $this->loadAndPrune(time());
		$state[$token] = ['uuid' => (string) $peer->getUuid(), 'exp' => $expiresAt];
		if (count($state) > self::MAX_TOKENS) {
			uasort($state, static fn (array $a, array $b): int => $a['exp'] <=> $b['exp']);
			$state = array_slice($state, -self::MAX_TOKENS, null, true);
		}
		$this->persist($state);

		return [
			'ok' => true,
			'http_code' => 200,
			'oneTimeLink' => $token,
			'redeemPath' => '/index.php/apps/' . Application::APP_ID
				. '/api/peers/otl/' . rawurlencode($token),
			'expiresAt' => gmdate('c', $expiresAt),
		];
	}

	/**
	 * Consume a token and render the peer's config.
	 *
	 * Mirrors `WireGuardEngineInterface::redeemOneTimeLink()` so the controller
	 * can treat NC-minted and engine-minted links the same way.
	 *
	 * @return array{ok: bool, http_code: int, body: string|false, error: string, content_type: string, code?: string, filename?: string}
	 */
	public function redeem(string $token): array
	{
		$token = trim($token);
		$now = time();
		// Expired entries are kept for this read so an operator whose link
		// aged out gets "expired" rather than the misleading "unknown".
		$state = $this->loadAndPrune($now, true);
		$entry = $state[$token] ?? null;
		if ($entry === null) {
			return $this->failure(404, 'Unknown or already redeemed one-time link', self::ERR_UNKNOWN);
		}

		unset($state[$token]);
		$this->persist($state);

		if ($entry['exp'] <= $now) {
			return $this->failure(410, 'One-time link expired', self::ERR_EXPIRED);
		}

		$peer = $this->peers->findByUuid($entry['uuid']);
		if ($peer === null) {
			return $this->failure(404, 'Peer no longer exists', self::ERR_UNKNOWN);
		}

		try {
			$body = $this->confBuilder->buildForPeer($peer);
		} catch (Throwable $e) {
			$this->logger->error('nc_wireguard NC OTL: config build failed for {uuid}: {err}', [
				'uuid' => $entry['uuid'],
				'err' => $e->getMessage(),
			]);
			return $this->failure(500, 'Could not build peer configuration');
		}

		return [
			'ok' => true,
			'http_code' => 200,
			'body' => $body,
			'error' => '',
			'content_type' => 'text/plain',
			'filename' => $this->confBuilder->filenameFor($peer),
		];
	}

	/** Drop every outstanding token, e.g. when freezing writes before cutover. */
	public function revokeAll(): void
	{
		$this->persist([]);
	}

	/**
	 * @return array{ok: false, http_code: int, body: false, error: string, content_type: string, code?: string}
	 */
	private function failure(int $httpCode, string $error, ?string $code = null): array
	{
		$out = [
			'ok' => false,
			'http_code' => $httpCode,
			'body' => false,
			'error' => $error,
			'content_type' => 'text/plain',
		];
		if ($code !== null) {
			$out['code'] = $code;
		}
		return $out;
	}

	/**
	 * @return array<string, array{uuid: string, exp: int}>
	 */
	private function loadAndPrune(int $now, bool $keepExpired = false): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '{}');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $token => $entry) {
			if (!is_string($token) || $token === '' || !is_array($entry)) {
				continue;
			}
			$uuid = $entry['uuid'] ?? null;
			$exp = $entry['exp'] ?? null;
			if (!is_string($uuid) || $uuid === '' || !is_numeric($exp)) {
				continue;
			}
			if (!$keepExpired && (int) $exp <= $now) {
				continue;
			}
			$out[$token] = ['uuid' => $uuid, 'exp' => (int) $exp];
		}
		return $out;
	}

	/**
	 * @param array<string, array{uuid: string, exp: int}> $state
	 */
	private function persist(array $state): void
	{
		$now = time();
		$live = array_filter($state, static fn (array $entry): bool => $entry['exp'] > $now);
		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($live) ?: '{}'
		);
	}

	/**
	 * URL-safe so the token drops straight into the redeem route, whose
	 * requirement is `[A-Za-z0-9_-]+`.
	 */
	public static function newToken(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
	}
}
