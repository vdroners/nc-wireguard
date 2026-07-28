<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCP\IConfig;

/** In-memory appconfig so settings tests do not need a Nextcloud container. */
final class ArrayConfig implements IConfig
{
	/**
	 * @param array<string, string> $values
	 */
	public function __construct(private array $values = [])
	{
	}

	public function getAppValue(string $app, string $key, string $default = ''): string
	{
		return $this->values[$key] ?? $default;
	}

	public function setAppValue(string $app, string $key, string $value): void
	{
		$this->values[$key] = $value;
	}

	public function deleteAppValue(string $app, string $key): void
	{
		unset($this->values[$key]);
	}

	/** @return array<string, string> */
	public function all(): array
	{
		return $this->values;
	}
}
