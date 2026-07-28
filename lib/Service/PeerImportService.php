<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Util\EnginePeerRow;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds the peer-import plan that seeds the NC store from wg-easy.
 *
 * Two sources, same output shape:
 *
 *  - **live engine** — `listPeers()` for the inventory, then `getPeer()` per
 *    peer because wg-easy's list endpoint omits private keys;
 *  - **export dir** — the `peers/*.json` + `conf/*.conf` tree written by
 *    `scripts/export-peers.sh`, for hosts that cannot reach the engine.
 *
 * Planning is read-only and never touches the DB, so a dry run is genuinely
 * dry. Rows carry key material; callers must not log or print them.
 */
class PeerImportService
{
	public function __construct(
		private WireGuardEngineInterface $engine,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, skipped: list<string>, source: string}
	 */
	public function planFromEngine(): array
	{
		$peers = $this->engine->listPeers();
		if ($peers === null) {
			throw new RuntimeException('Engine is unreachable — cannot enumerate peers');
		}

		$rows = [];
		$skipped = [];
		foreach ($peers as $peer) {
			if (!is_array($peer)) {
				continue;
			}
			$row = EnginePeerRow::fromEnginePeer($peer);
			$wgEasyId = $row['wg_easy_id'];

			// get-one carries the secrets the list endpoint strips.
			if (is_int($wgEasyId)) {
				$detail = $this->engine->getPeer($wgEasyId);
				if (is_array($detail)) {
					$row = EnginePeerRow::merge($row, EnginePeerRow::fromEnginePeer($detail));
				} else {
					$this->logger->warning(
						'nc_wireguard import: get-one failed for peer {id}; importing without key material',
						['id' => $wgEasyId]
					);
				}
			}

			if (($row['public_key'] ?? '') === '') {
				$skipped[] = ($row['name'] ?? '(unnamed)') . ': engine returned no public key';
				continue;
			}
			$row['notes'] = EnginePeerRow::notesFor($row);
			$rows[] = $row;
		}

		return ['rows' => $rows, 'skipped' => $skipped, 'source' => 'engine'];
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, skipped: list<string>, source: string}
	 */
	public function planFromExport(string $dir): array
	{
		$root = rtrim($dir, '/');
		$peersDir = $root . '/peers';
		if (!is_dir($peersDir)) {
			throw new RuntimeException('Export dir has no peers/ subdirectory: ' . $root);
		}
		$files = glob($peersDir . '/*.json');
		if ($files === false || $files === []) {
			throw new RuntimeException('No peers/*.json files found under ' . $root);
		}
		sort($files);

		$rows = [];
		$skipped = [];
		foreach ($files as $file) {
			$raw = @file_get_contents($file);
			$peer = $raw === false ? null : json_decode($raw, true);
			if (!is_array($peer)) {
				$skipped[] = basename($file) . ': not readable as JSON';
				continue;
			}
			$row = EnginePeerRow::fromEnginePeer($peer);

			$conf = $root . '/conf/' . pathinfo($file, PATHINFO_FILENAME) . '.conf';
			if (is_file($conf)) {
				$body = @file_get_contents($conf);
				if (is_string($body) && $body !== '') {
					$row = EnginePeerRow::merge($row, EnginePeerRow::fromConf($body));
				}
			}

			if (($row['public_key'] ?? '') === '') {
				$skipped[] = basename($file) . ': no public key in export';
				continue;
			}
			$row['notes'] = EnginePeerRow::notesFor($row);
			$rows[] = $row;
		}

		return ['rows' => $rows, 'skipped' => $skipped, 'source' => 'export:' . $root];
	}

	/**
	 * Operator-facing, secret-free view of one planned row.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	public static function describe(array $row): array
	{
		$keepalive = $row['persistent_keepalive'] ?? null;
		return [
			'wg_easy_id' => isset($row['wg_easy_id']) ? (string) $row['wg_easy_id'] : '-',
			'name' => (string) ($row['name'] ?? '-'),
			'public_key' => self::fingerprint((string) ($row['public_key'] ?? '')),
			'ipv4' => (string) ($row['ipv4'] ?? '(IPAM)'),
			'keepalive' => $keepalive === null ? '-' : (string) $keepalive,
			'key' => ($row['private_key'] ?? null) !== null ? 'yes' : 'no',
			'psk' => ($row['psk'] ?? null) !== null ? 'yes' : 'no',
			'flags' => implode(' ', array_filter([
				($row['break_glass'] ?? false) === true ? 'break-glass' : '',
				($row['has_amnezia'] ?? false) === true ? 'amnezia' : '',
				($row['enabled'] ?? true) === false ? 'disabled' : '',
			])),
		];
	}

	/**
	 * Public keys are not secret, but a truncated form keeps import output
	 * readable and keeps whole keys out of scrollback and CI logs.
	 */
	public static function fingerprint(string $publicKey): string
	{
		if ($publicKey === '') {
			return '-';
		}
		return strlen($publicKey) <= 12 ? $publicKey : substr($publicKey, 0, 10) . '…';
	}
}
