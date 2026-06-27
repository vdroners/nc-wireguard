<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\MetricsPollService;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run one native metrics poll cycle (flock-guarded for systemd timer overlap).
 */
class PollMetricsCommand extends Command
{
	public function __construct(
		private MetricsPollService $pollService,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:poll-metrics')
			->setDescription('Poll wg-easy and persist bandwidth, connections, system metrics')
			->addOption('no-lock', null, InputOption::VALUE_NONE, 'Skip flock (tests only)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$skipLock = (bool) $input->getOption('no-lock');
		$lockFp = null;
		if (!$skipLock) {
			$lockPath = $this->lockPath();
			$lockFp = @fopen($lockPath, 'c');
			if ($lockFp === false) {
				$output->writeln('<error>Cannot open lock file: ' . $lockPath . '</error>');
				return Command::FAILURE;
			}
			if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
				$output->writeln('<comment>Poll already running (flock busy)</comment>');
				return Command::SUCCESS;
			}
		}

		try {
			$result = $this->pollService->poll();
			if (!$result['ok']) {
				$output->writeln('<error>Poll failed: ' . $result['error'] . '</error>');
				return Command::FAILURE;
			}
			$output->writeln(sprintf(
				'<info>Poll OK: %d clients, %d bandwidth rows, %d connection events</info>',
				$result['clients'],
				$result['bandwidth_rows'],
				$result['connection_events']
			));
			return Command::SUCCESS;
		} finally {
			if (is_resource($lockFp)) {
				flock($lockFp, LOCK_UN);
				fclose($lockFp);
			}
		}
	}

	private function lockPath(): string
	{
		$dataDir = (string) $this->config->getSystemValue('datadirectory', sys_get_temp_dir());
		return rtrim($dataDir, '/') . '/' . Application::APP_ID . '_poll.lock';
	}
}
