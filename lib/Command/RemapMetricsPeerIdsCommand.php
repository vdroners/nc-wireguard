<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\MetricsPeerRemapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill stable peer identities on the metrics tables (P6 cutover step).
 *
 * Dry run by default. Safe to run against production while `engine=wgeasy`: it
 * only fills columns that are currently blank and never touches the engine.
 */
class RemapMetricsPeerIdsCommand extends Command
{
	public function __construct(
		private MetricsPeerRemapper $remapper,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:remap-metrics')
			->setDescription('Backfill peer_uuid / public_key on metrics rows keyed by wg-easy client id')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (default is a dry run)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dryRun = !(bool) $input->getOption('apply');
		$result = $this->remapper->run($dryRun);

		$output->writeln('<info>Peers with a wg-easy id: ' . $result['mapped'] . '</info>');
		if ($result['mapped'] === 0) {
			$output->writeln(
				'<comment>No imported peer carries a wg-easy id — run '
				. 'occ nc_wireguard:import-peers first, or history cannot be matched.</comment>'
			);
		}

		$table = new Table($output);
		$table->setHeaders(['table', 'rows needing backfill', 'rows written', 'orphaned rows']);
		foreach ($result['tables'] as $name => $counts) {
			$table->addRow([$name, $counts['matched'], $counts['updated'], $counts['orphaned']]);
		}
		$table->render();

		if ($result['unmapped'] !== []) {
			$output->writeln(
				'<comment>No peer for wg-easy client ids: '
				. implode(', ', $result['unmapped'])
				. ' — their history stays queryable by client_id but will not follow a peer '
				. 'forward. Usually peers deleted before the import.</comment>'
			);
		}

		if ($dryRun) {
			$output->writeln('<info>Dry run — nothing written. Re-run with --apply.</info>');
		} else {
			$output->writeln('<info>remap-metrics: identities written.</info>');
		}

		return Command::SUCCESS;
	}
}
