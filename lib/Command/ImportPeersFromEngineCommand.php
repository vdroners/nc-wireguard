<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\PeerImportService;
use OCA\NcWireguard\Service\PeerStoreService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed the NC peer store from the live engine or from an export directory.
 *
 * Re-runnable: peers are matched on public key, and stored key material is left
 * alone unless `--allow-key-rewrite` is passed. Nothing is written to the engine,
 * so this is safe to run against production while `engine=wgeasy`.
 */
class ImportPeersFromEngineCommand extends Command
{
	public function __construct(
		private PeerImportService $import,
		private PeerStoreService $store,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:import-peers')
			->setDescription('Import peers (and key material) from the WireGuard engine into the NC peer store')
			->addOption(
				'from-export',
				null,
				InputOption::VALUE_REQUIRED,
				'Import from an export dir written by scripts/export-peers.sh instead of the live engine'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Show what would be imported without writing to the peer store'
			)
			->addOption(
				'allow-key-rewrite',
				null,
				InputOption::VALUE_NONE,
				'Overwrite key material already stored for a peer (off by default)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$exportDir = $input->getOption('from-export');
		$dryRun = (bool) $input->getOption('dry-run');
		$allowKeyRewrite = (bool) $input->getOption('allow-key-rewrite');

		try {
			$plan = is_string($exportDir) && $exportDir !== ''
				? $this->import->planFromExport($exportDir)
				: $this->import->planFromEngine();
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Import source: ' . $plan['source'] . '</info>');
		if ($this->store->isShadowMode()) {
			$output->writeln(
				'<comment>engine=wgeasy — the NC peer store is a shadow copy; '
				. 'nothing is written back to the engine.</comment>'
			);
		}

		if ($plan['rows'] === []) {
			$output->writeln('<comment>No importable peers found.</comment>');
			return Command::SUCCESS;
		}

		$this->renderPlan($output, $plan['rows']);

		foreach ($plan['skipped'] as $skip) {
			$output->writeln('<comment>skipped ' . $skip . '</comment>');
		}

		if ($dryRun) {
			$output->writeln('<info>Dry run — peer store not modified (' . count($plan['rows']) . ' peers).</info>');
			return Command::SUCCESS;
		}

		$imported = 0;
		$failed = 0;
		foreach ($plan['rows'] as $row) {
			$label = (string) ($row['name'] ?? '(unnamed)');
			try {
				$peer = $this->store->upsert($row, $allowKeyRewrite);
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln('<error>' . $label . ': ' . $e->getMessage() . '</error>');
				continue;
			}
			$imported++;
			$output->writeln(sprintf(
				'  stored %s uuid=%s ipv4=%s',
				$label,
				(string) $peer->getUuid(),
				(string) ($peer->getIpv4() ?? '-')
			));
		}

		$output->writeln(sprintf(
			'<info>import-peers: %d stored, %d failed, %d skipped</info>',
			$imported,
			$failed,
			count($plan['skipped'])
		));

		return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function renderPlan(OutputInterface $output, array $rows): void
	{
		$table = new Table($output);
		$table->setHeaders(['wg-easy id', 'name', 'public key', 'ipv4', 'keepalive', 'key', 'psk', 'flags']);
		$notes = [];
		foreach ($rows as $row) {
			$view = PeerImportService::describe($row);
			$table->addRow(array_values($view));
			foreach ($row['notes'] ?? [] as $note) {
				$notes[] = $view['name'] . ': ' . $note;
			}
		}
		$table->render();

		foreach ($notes as $note) {
			$output->writeln('<comment>note ' . $note . '</comment>');
		}
	}
}
