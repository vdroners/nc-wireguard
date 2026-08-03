<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\SidecarImportService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import wg-dashboard sidecar SQLite metrics into NC native tables.
 */
class ImportSidecarDbCommand extends Command
{
	public function __construct(
		private SidecarImportService $importService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:import-sidecar-db')
			->setDescription('Import sidecar SQLite metrics into NC native tables (idempotent)')
			->addArgument(
				'sqlite-path',
				InputArgument::REQUIRED,
				'Absolute path to dashboard.db (no default — configure explicitly)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = trim((string) $input->getArgument('sqlite-path'));
		if ($path === '') {
			$output->writeln('<error>sqlite-path is required (no lab host default).</error>');
			return Command::FAILURE;
		}
		try {
			$result = $this->importService->import($path);
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Import from ' . $result['sqlite_path'] . '</info>');
		foreach (['bandwidth_log', 'connection_log', 'geoip_cache', 'system_metrics', 'poll_state'] as $table) {
			$output->writeln(sprintf(
				'  %s: inserted=%d skipped=%d (source=%d nc=%d)',
				$table,
				$result['inserted'][$table],
				$result['skipped'][$table],
				$result['source_counts'][$table],
				$result['nc_counts_after'][$table]
			));
		}

		return Command::SUCCESS;
	}
}
