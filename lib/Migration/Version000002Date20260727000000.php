<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NC peer store (P3): peers, encrypted peer secrets, and the singleton server
 * row so Nextcloud can own peer identity instead of wg-easy.
 *
 * Nothing here changes runtime behaviour — while `engine=wgeasy` the store is a
 * shadow copy. The nullable `peer_uuid` / `public_key` columns added to the
 * metrics tables are remap headroom for P6, when historical rows keyed by the
 * wg-easy integer id have to be re-pointed at a stable identity.
 */
class Version000002Date20260727000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createPeers($schema);
		$this->createPeerSecrets($schema);
		$this->createServer($schema);
		$this->addMetricsRemapColumns($schema);

		return $schema;
	}

	private function createPeers(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_peers')) {
			return;
		}
		$table = $schema->createTable('nc_wg_peers');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('public_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('wg_easy_id', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => 1]);
		// Stored with prefix, e.g. 10.8.0.5/32.
		$table->addColumn('ipv4', Types::STRING, ['notnull' => false, 'length' => 49]);
		$table->addColumn('allowed_ips', Types::TEXT, ['notnull' => false]);
		$table->addColumn('dns', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('mtu', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('persistent_keepalive', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('server_endpoint', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('server_allowed_ips', Types::TEXT, ['notnull' => false]);
		$table->addColumn('firewall_ips', Types::TEXT, ['notnull' => false]);
		// Amnezia obfuscation (jC/jMin/jMax/i1..i5) has no native equivalent, so
		// NativeEngine must refuse such a peer rather than silently drop it.
		$table->addColumn('has_amnezia', Types::BOOLEAN, ['notnull' => true, 'default' => 0]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'nc_wg_peer_uuid_uidx');
		$table->addUniqueIndex(['public_key'], 'nc_wg_peer_pk_uidx');
		$table->addIndex(['wg_easy_id'], 'nc_wg_peer_wgeid_idx');
	}

	private function createPeerSecrets(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_peer_secrets')) {
			return;
		}
		$table = $schema->createTable('nc_wg_peer_secrets');
		$table->addColumn('peer_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('private_key_enc', Types::TEXT, ['notnull' => true]);
		$table->addColumn('psk_enc', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['peer_id']);
	}

	private function createServer(ISchemaWrapper $schema): void
	{
		if ($schema->hasTable('nc_wg_server')) {
			return;
		}
		$table = $schema->createTable('nc_wg_server');
		$table->addColumn('id', Types::INTEGER, ['notnull' => true, 'default' => 1]);
		$table->addColumn('host', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('port', Types::INTEGER, ['notnull' => false, 'default' => 51820]);
		$table->addColumn('cidr', Types::STRING, ['notnull' => true, 'length' => 49, 'default' => '10.8.0.0/24']);
		$table->addColumn('mtu', Types::INTEGER, ['notnull' => false, 'default' => 1420]);
		$table->addColumn('default_dns', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('default_allowed_ips', Types::TEXT, ['notnull' => false]);
		$table->addColumn('default_keepalive', Types::INTEGER, ['notnull' => false, 'default' => 25]);
		// Preserved verbatim at cutover so field peers do not have to re-issue.
		$table->addColumn('server_public_key', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('ipv4_only', Types::BOOLEAN, ['notnull' => true, 'default' => 1]);
		$table->setPrimaryKey(['id']);
	}

	private function addMetricsRemapColumns(ISchemaWrapper $schema): void
	{
		foreach (['nc_wg_bandwidth_log', 'nc_wg_connection_log'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if (!$table->hasColumn('peer_uuid')) {
				$table->addColumn('peer_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			}
		}

		if (!$schema->hasTable('nc_wg_poll_state')) {
			return;
		}
		$pollState = $schema->getTable('nc_wg_poll_state');
		if (!$pollState->hasColumn('peer_uuid')) {
			$pollState->addColumn('peer_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		}
		if (!$pollState->hasColumn('public_key')) {
			$pollState->addColumn('public_key', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
	}
}
