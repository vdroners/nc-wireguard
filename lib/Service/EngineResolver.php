<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use OCA\NcWireguard\Db\PeerMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides which engine `WireGuardEngineInterface` resolves to (P5).
 *
 * `engine=native` on its own is not enough. A native engine reading a
 * half-populated peer store would present the entire field fleet as deleted and
 * — worse — push that empty set to the sidecar. So three things must all hold:
 *
 *  1. `engine` is set to `native`;
 *  2. the operator has confirmed the import with `import_complete`;
 *  3. the peer store actually has rows.
 *
 * Anything short of that falls back to wg-easy and logs why. Failing open to
 * the engine that is currently carrying production traffic is the safe
 * direction for this particular switch.
 */
class EngineResolver
{
	public function __construct(
		private AppSettings $settings,
		private PeerMapper $peers,
		private LoggerInterface $logger,
	) {
	}

	public function useNative(): bool
	{
		return $this->blockReason() === null;
	}

	/**
	 * Why the native engine is not active, or null when it is.
	 *
	 * Returns null when `engine` is `native` and every precondition is met;
	 * otherwise an operator-facing sentence. Safe to call from a settings page.
	 */
	public function blockReason(): ?string
	{
		if ($this->settings->getEngine() !== AppSettings::ENGINE_NATIVE) {
			return 'engine is set to wgeasy';
		}
		if (!$this->settings->isImportComplete()) {
			return 'peer import is not marked complete — run '
				. 'occ nc_wireguard:import-peers and verify before switching';
		}
		try {
			$count = $this->peers->countAll();
		} catch (Throwable $e) {
			$this->logger->error(
				'nc_wireguard engine resolver: peer store unreadable, staying on wg-easy: {err}',
				['err' => $e->getMessage()]
			);
			return 'peer store is unreadable';
		}
		if ($count < 1) {
			return 'peer store is empty';
		}
		return null;
	}

	/**
	 * Engine actually in force, which is not always what `engine` says.
	 */
	public function activeEngine(): string
	{
		return $this->useNative() ? AppSettings::ENGINE_NATIVE : AppSettings::ENGINE_WG_EASY;
	}

	/**
	 * @return array<string, mixed> operator-facing status for the admin page
	 */
	public function status(): array
	{
		$reason = $this->blockReason();
		return [
			'configured' => $this->settings->getEngine(),
			'active' => $reason === null ? AppSettings::ENGINE_NATIVE : AppSettings::ENGINE_WG_EASY,
			'blocked' => $reason !== null
				&& $this->settings->getEngine() === AppSettings::ENGINE_NATIVE,
			'reason' => $reason,
		];
	}
}
