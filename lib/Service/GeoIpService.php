<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\NcWireguard\Db\GeoIpCache;
use OCA\NcWireguard\Db\GeoIpCacheMapper;
use Psr\Log\LoggerInterface;

/**
 * ip-api.com lookups with 7-day NC DB cache (nc_wg_geoip_cache).
 */
class GeoIpService
{
	public const REFRESH_DAYS = 7;

	public function __construct(
		private GeoIpCacheMapper $cacheMapper,
		private AppSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function resolve(string $ip): void
	{
		if (!$this->settings->isGeoIpEnabled()) {
			return;
		}
		$ip = trim($ip);
		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			return;
		}

		$cached = $this->cacheMapper->findByIp($ip);
		if ($cached !== null && !$this->isStale($cached->getQueriedAt())) {
			return;
		}

		$data = $this->lookupRemote($ip);
		if ($data === null) {
			return;
		}

		$entity = $cached ?? new GeoIpCache();
		$entity->setIp($ip);
		$entity->setCountry($data['country'] ?? null);
		$entity->setCountryCode($data['countryCode'] ?? null);
		$entity->setCity($data['city'] ?? null);
		$entity->setRegion($data['regionName'] ?? null);
		$entity->setLat(isset($data['lat']) ? (float) $data['lat'] : null);
		$entity->setLon(isset($data['lon']) ? (float) $data['lon'] : null);
		$entity->setIsp($data['isp'] ?? null);
		$entity->setQueriedAt(new \DateTime('now', new DateTimeZone('UTC')));

		$this->cacheMapper->upsert($entity);
	}

	private function isStale(\DateTime $queriedAt): bool
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$queried = DateTimeImmutable::createFromInterface($queriedAt)->setTimezone(new DateTimeZone('UTC'));
		return $queried->diff($now)->days >= self::REFRESH_DAYS;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function lookupRemote(string $ip): ?array
	{
		$fields = 'status,country,countryCode,regionName,city,lat,lon,isp';
		$url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=' . $fields;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$body = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $error !== '' || $httpCode !== 200) {
			$this->logger->warning('nc_wireguard: GeoIP lookup failed', [
				'ip' => $ip,
				'http_code' => $httpCode,
				'error' => $error,
			]);
			return null;
		}

		$data = json_decode((string) $body, true);
		if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
			return null;
		}
		return $data;
	}
}
