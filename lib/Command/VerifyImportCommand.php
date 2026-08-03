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
 * Verify sidecar SQLite rows were imported into NC native tables.
 */
class VerifyImportCommand extends Command
{
	public function __construct(
		private SidecarImportService $importService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:verify-import')
			->setDescription('Compare sidecar SQLite row counts against NC native tables')
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
			$result = $this->importService->verify($path);
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Verify import: ' . $result['sqlite_path'] . '</info>');
		foreach ($result['tables'] as $table => $stats) {
			$status = $stats['missing'] === 0 ? 'OK' : 'FAIL';
			$output->writeln(sprintf(
				'  %s [%s]: source=%d nc=%d missing=%d',
				$table,
				$status,
				$stats['source'],
				$stats['nc'],
				$stats['missing']
			));
		}

		$poll = $result['poll_state'];
		$pollStatus = $poll['key_mismatches'] === [] ? 'OK' : 'FAIL';
		$output->writeln(sprintf(
			'  poll_state [%s]: expected=%d nc=%d mismatches=%d',
			$pollStatus,
			$poll['expected'],
			$poll['nc'],
			count($poll['key_mismatches'])
		));

		if (!$result['ok']) {
			foreach ($result['errors'] as $err) {
				$output->writeln('<error>' . $err . '</error>');
			}
			return Command::FAILURE;
		}

		$output->writeln('<info>verify-import PASSED</info>');
		return Command::SUCCESS;
	}
}
