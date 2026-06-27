<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\PollState;

/**
 * 180 s handshake timeout connection FSM (mirrors wg-dashboard sidecar).
 */
class ConnectionStateMachine
{
	public const HANDSHAKE_TIMEOUT_SEC = 180;

	/**
	 * @param array<string, mixed> $client
	 */
	public function isConnected(array $client, ?DateTimeImmutable $now = null): bool
	{
		$hs = $client['latestHandshakeAt'] ?? null;
		if (!is_string($hs) || $hs === '') {
			return false;
		}
		$now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
		try {
			$hsDt = new DateTimeImmutable(str_replace('Z', '+00:00', $hs));
			return ($now->getTimestamp() - $hsDt->getTimestamp()) < self::HANDSHAKE_TIMEOUT_SEC;
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $client
	 * @return list<array{event: string, endpoint: string|null}>
	 */
	public function transitionEvents(
		array $client,
		?PollState $previous,
		bool $connected,
	): array {
		$endpoint = isset($client['endpoint']) && is_string($client['endpoint'])
			? $client['endpoint']
			: null;
		$wasConnected = $previous !== null && $previous->getConnected();
		$events = [];

		if ($connected && !$wasConnected) {
			$events[] = ['event' => 'connected', 'endpoint' => $endpoint];
		} elseif (!$connected && $wasConnected) {
			$prevEndpoint = $previous?->getEndpoint();
			$events[] = ['event' => 'disconnected', 'endpoint' => $prevEndpoint];
		}

		return $events;
	}
}
