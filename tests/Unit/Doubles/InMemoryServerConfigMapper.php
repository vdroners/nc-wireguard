<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Tests\Unit\Doubles;

use OCA\NcWireguard\Db\ServerConfig;
use OCA\NcWireguard\Db\ServerConfigMapper;
use OCA\NcWireguard\Tests\Stubs\NullDbConnection;

/** Singleton server row held in memory. */
final class InMemoryServerConfigMapper extends ServerConfigMapper
{
	public function __construct(private ?ServerConfig $row = null)
	{
		parent::__construct(new NullDbConnection());
	}

	public function load(): ?ServerConfig
	{
		return $this->row;
	}

	public function save(ServerConfig $config): ServerConfig
	{
		$config->setId(ServerConfig::SINGLETON_ID);
		$this->row = $config;
		return $config;
	}
}
