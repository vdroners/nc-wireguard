<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCA\NcWireguard\Service\WgSyncClient;

/**
 * wg-sync client that records what would have been sent.
 *
 * Overrides the one protected seam (`request`) rather than the four public
 * methods, so the envelope shape the engine consumes is still the real one.
 */
final class RecordingWgSyncClient extends WgSyncClient
{
	/** @var list<array<string, mixed>> bodies passed to POST /apply */
	public array $applied = [];

	public bool $reachable = true;

	public function isConfigured(): bool
	{
		return true;
	}

	protected function request(string $method, string $path, ?array $body = null): array
	{
		if (!$this->reachable) {
			return ['ok' => false, 'http_code' => 0, 'error' => 'wg-sync unreachable', 'json' => null];
		}
		if ($path === '/apply') {
			$this->applied[] = $body ?? [];
			return [
				'ok' => true,
				'http_code' => 200,
				'error' => '',
				'json' => ['ok' => true, 'peer_count' => count($body['peers'] ?? [])],
			];
		}
		if ($path === '/dump') {
			return [
				'ok' => true,
				'http_code' => 200,
				'error' => '',
				'json' => ['interface' => 'wg-lab0', 'up' => true, 'peers' => []],
			];
		}
		return [
			'ok' => true,
			'http_code' => 200,
			'error' => '',
			'json' => ['ok' => true, 'interface' => 'wg-lab0', 'up' => true, 'peer_count' => 0],
		];
	}
}
