<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\MetricsPruneService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete nc_wireguard metrics rows older than configured retention.
 */
class PruneMetricsCommand extends Command
{
	public function __construct(
		private MetricsPruneService $pruneService,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:prune-metrics')
			->setDescription('Prune nc_wireguard metrics tables by retention_days setting');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$counts = $this->pruneService->prune();
		$output->writeln(sprintf(
			'<info>Pruned: bandwidth=%d connections=%d system=%d geoip=%d</info>',
			$counts['bandwidth'],
			$counts['connections'],
			$counts['system'],
			$counts['geoip']
		));
		return Command::SUCCESS;
	}
}
