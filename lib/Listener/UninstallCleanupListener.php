<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Listener;

use OCA\NcWireguard\AppInfo\Application;
use OCP\App\Events\AppUninstallEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Drop nc_wg_* tables and appconfig on uninstall.
 *
 * @template-implements IEventListener<AppUninstallEvent>
 */
class UninstallCleanupListener implements IEventListener
{
	private const TABLES = [
		'nc_wg_bandwidth_log',
		'nc_wg_connection_log',
		'nc_wg_geoip_cache',
		'nc_wg_system_metrics',
		'nc_wg_poll_state',
		'nc_wg_metrics_heartbeat',
		'nc_wg_peer_secrets',
		'nc_wg_peers',
		'nc_wg_server',
	];

	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof AppUninstallEvent || $event->getAppId() !== Application::APP_ID) {
			return;
		}

		$prefix = $this->db->getPrefix();
		foreach (self::TABLES as $table) {
			$this->db->executeStatement('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
		}

		$this->config->deleteAppValues(Application::APP_ID);
	}
}
