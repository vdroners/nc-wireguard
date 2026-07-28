<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A WireGuard peer as Nextcloud knows it.
 *
 * `uuid` is the identity NC hands out, `publicKey` is the identity the
 * dataplane agrees on, and `wgEasyId` is kept only so historical metrics rows
 * (which are keyed by the wg-easy integer id) can be remapped at cutover.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getPublicKey()
 * @method void setPublicKey(string $publicKey)
 * @method int|null getWgEasyId()
 * @method void setWgEasyId(?int $wgEasyId)
 * @method string getName()
 * @method void setName(string $name)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string|null getIpv4()
 * @method void setIpv4(?string $ipv4)
 * @method string|null getAllowedIps()
 * @method void setAllowedIps(?string $allowedIps)
 * @method string|null getDns()
 * @method void setDns(?string $dns)
 * @method int|null getMtu()
 * @method void setMtu(?int $mtu)
 * @method int|null getPersistentKeepalive()
 * @method void setPersistentKeepalive(?int $persistentKeepalive)
 * @method string|null getServerEndpoint()
 * @method void setServerEndpoint(?string $serverEndpoint)
 * @method string|null getServerAllowedIps()
 * @method void setServerAllowedIps(?string $serverAllowedIps)
 * @method string|null getFirewallIps()
 * @method void setFirewallIps(?string $firewallIps)
 * @method bool getHasAmnezia()
 * @method void setHasAmnezia(bool $hasAmnezia)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Peer extends Entity
{
	protected $uuid;
	protected $publicKey;
	protected $wgEasyId;
	protected $name;
	protected $enabled;
	protected $ipv4;
	protected $allowedIps;
	protected $dns;
	protected $mtu;
	protected $persistentKeepalive;
	protected $serverEndpoint;
	protected $serverAllowedIps;
	protected $firewallIps;
	protected $hasAmnezia;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('wgEasyId', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('mtu', 'integer');
		$this->addType('persistentKeepalive', 'integer');
		$this->addType('hasAmnezia', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
