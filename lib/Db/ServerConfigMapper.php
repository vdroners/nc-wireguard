<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ServerConfig> */
class ServerConfigMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_server', ServerConfig::class);
	}

	/**
	 * The singleton row, or null before the first save.
	 */
	public function load(): ?ServerConfig
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq(
				'id',
				$qb->createNamedParameter(ServerConfig::SINGLETON_ID, IQueryBuilder::PARAM_INT)
			))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function save(ServerConfig $config): ServerConfig
	{
		$config->setId(ServerConfig::SINGLETON_ID);
		if ($this->load() === null) {
			return $this->insert($config);
		}
		return $this->update($config);
	}
}
