<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\SchemaRegistry;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verify native metrics tables exist with expected columns after migration.
 */
class SchemaCheckCommand extends Command
{
	public function __construct(
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:schema-check')
			->setDescription('Verify nc_wireguard DB schema (metrics + peer store tables)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$schema = $this->db->createSchema();
		$errors = [];

		foreach (SchemaRegistry::TABLES as $tableName => $expectedColumns) {
			$physical = $this->resolvePhysicalTableName($schema, $tableName);
			if ($physical === null) {
				$errors[] = "Missing table: {$tableName}";
				continue;
			}
			$table = $schema->getTable($physical);
			foreach ($expectedColumns as $column) {
				if (!$table->hasColumn($column)) {
					$errors[] = "Missing column {$tableName}.{$column}";
				}
			}
		}

		if ($errors !== []) {
			foreach ($errors as $err) {
				$output->writeln('<error>' . $err . '</error>');
			}
			return Command::FAILURE;
		}

		$output->writeln('<info>nc_wireguard schema OK (' . count(SchemaRegistry::TABLES) . ' tables)</info>');
		return Command::SUCCESS;
	}

	private function resolvePhysicalTableName(object $schema, string $logicalName): ?string
	{
		if ($schema->hasTable($logicalName)) {
			return $logicalName;
		}
		foreach ($schema->getTableNames() as $physical) {
			if ($physical === $logicalName || str_ends_with($physical, '_' . $logicalName)) {
				return $physical;
			}
		}
		return null;
	}
}
