<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getIp()
 * @method void setIp(string $ip)
 * @method string|null getCountry()
 * @method void setCountry(?string $country)
 * @method string|null getCountryCode()
 * @method void setCountryCode(?string $countryCode)
 * @method string|null getCity()
 * @method void setCity(?string $city)
 * @method string|null getRegion()
 * @method void setRegion(?string $region)
 * @method float|null getLat()
 * @method void setLat(?float $lat)
 * @method float|null getLon()
 * @method void setLon(?float $lon)
 * @method string|null getIsp()
 * @method void setIsp(?string $isp)
 * @method \DateTime getQueriedAt()
 * @method void setQueriedAt(\DateTime $queriedAt)
 */
class GeoIpCache extends Entity
{
	protected $ip;
	protected $country;
	protected $countryCode;
	protected $city;
	protected $region;
	protected $lat;
	protected $lon;
	protected $isp;
	protected $queriedAt;

	public function __construct()
	{
		$this->addType('lat', 'float');
		$this->addType('lon', 'float');
		$this->addType('queriedAt', 'datetime');
	}

	public function getId(): ?string
	{
		return $this->ip;
	}

	public function setId($id): void
	{
		$this->setIp((string) $id);
	}
}
