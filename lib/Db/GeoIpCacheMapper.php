<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use DateTimeImmutable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<GeoIpCache> */
class GeoIpCacheMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'nc_wg_geoip_cache', GeoIpCache::class);
	}

	public function findByIp(string $ip): ?GeoIpCache
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ip', $qb->createNamedParameter($ip, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteQueriedBefore(DateTimeImmutable $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt(
				'queried_at',
				$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
			));
		return $qb->executeStatement();
	}

	/**
	 * @return list<GeoIpCache>
	 */
	public function findAllOrdered(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('queried_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Geo fields attached to connection_log rows (subset of cache).
	 *
	 * @return array{country: string|null, country_code: string|null, city: string|null, lat: float|null, lon: float|null, isp: string|null}|null
	 */
	public function findGeoSummaryByIp(string $ip): ?array
	{
		$row = $this->findByIp($ip);
		if ($row === null) {
			return null;
		}
		return [
			'country' => $row->getCountry(),
			'country_code' => $row->getCountryCode(),
			'city' => $row->getCity(),
			'lat' => $row->getLat(),
			'lon' => $row->getLon(),
			'isp' => $row->getIsp(),
		];
	}

	public function upsert(GeoIpCache $entity): void
	{
		$existing = $this->findByIp($entity->getIp());
		if ($existing === null) {
			$this->insert($entity);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('country', $qb->createNamedParameter($entity->getCountry(), IQueryBuilder::PARAM_STR))
			->set('country_code', $qb->createNamedParameter($entity->getCountryCode(), IQueryBuilder::PARAM_STR))
			->set('city', $qb->createNamedParameter($entity->getCity(), IQueryBuilder::PARAM_STR))
			->set('region', $qb->createNamedParameter($entity->getRegion(), IQueryBuilder::PARAM_STR))
			->set('lat', $qb->createNamedParameter($entity->getLat(), IQueryBuilder::PARAM_STR))
			->set('lon', $qb->createNamedParameter($entity->getLon(), IQueryBuilder::PARAM_STR))
			->set('isp', $qb->createNamedParameter($entity->getIsp(), IQueryBuilder::PARAM_STR))
			->set('queried_at', $qb->createNamedParameter($entity->getQueriedAt(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('ip', $qb->createNamedParameter($entity->getIp(), IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
