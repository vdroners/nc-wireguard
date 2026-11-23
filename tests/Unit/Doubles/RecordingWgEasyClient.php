<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCA\NcWireguard\Service\AppSettings;
use OCA\NcWireguard\Service\WgEasyClient;
use Psr\Log\LoggerInterface;

/**
 * Records authedJson traffic so updateEndpointHost / getServerDefaults can be
 * asserted without live HTTP.
 */
final class RecordingWgEasyClient extends WgEasyClient
{
	/** @var list<array{0: string, 1: string, 2: ?array}> */
	public array $calls = [];

	/** @var list<array{http_code: int, error: string, json: mixed, code?: string}> */
	public array $responses = [];

	public function __construct(AppSettings $settings, LoggerInterface $logger)
	{
		parent::__construct($settings, $logger);
	}

	/**
	 * @param array<string, mixed>|null $jsonBody
	 * @return array{http_code: int, error: string, json: mixed, code?: string}
	 */
	protected function authedJson(string $method, string $path, ?array $jsonBody = null): array
	{
		$this->calls[] = [$method, $path, $jsonBody];
		if ($this->responses === []) {
			return ['http_code' => 500, 'error' => 'no canned response', 'json' => null];
		}
		return array_shift($this->responses);
	}
}
