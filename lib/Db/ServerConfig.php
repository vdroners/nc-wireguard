<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Singleton row (`id = 1`) in `nc_wg_server`: the interface-level facts NC needs
 * to build peer configs itself — endpoint, tunnel CIDR, MTU, and the defaults
 * that used to be hard-coded in the frontend presets.
 *
 * `serverPublicKey` is preserved verbatim across cutover so field peers do not
 * all have to re-issue, and `ipv4Only` encodes the P3 policy decision that the
 * native tunnel assigns no IPv6.
 *
 * @method string|null getHost()
 * @method void setHost(?string $host)
 * @method int|null getPort()
 * @method void setPort(?int $port)
 * @method string getCidr()
 * @method void setCidr(string $cidr)
 * @method int|null getMtu()
 * @method void setMtu(?int $mtu)
 * @method string|null getDefaultDns()
 * @method void setDefaultDns(?string $defaultDns)
 * @method string|null getDefaultAllowedIps()
 * @method void setDefaultAllowedIps(?string $defaultAllowedIps)
 * @method int|null getDefaultKeepalive()
 * @method void setDefaultKeepalive(?int $defaultKeepalive)
 * @method string|null getServerPublicKey()
 * @method void setServerPublicKey(?string $serverPublicKey)
 * @method bool getIpv4Only()
 * @method void setIpv4Only(bool $ipv4Only)
 */
class ServerConfig extends Entity
{
	public const SINGLETON_ID = 1;

	protected $host;
	protected $port;
	protected $cidr;
	protected $mtu;
	protected $defaultDns;
	protected $defaultAllowedIps;
	protected $defaultKeepalive;
	protected $serverPublicKey;
	protected $ipv4Only;

	public function __construct()
	{
		$this->addType('port', 'integer');
		$this->addType('mtu', 'integer');
		$this->addType('defaultKeepalive', 'integer');
		$this->addType('ipv4Only', 'boolean');
	}
}
