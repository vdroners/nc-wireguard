<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Command;

use OCA\NcWireguard\Service\ServerKeyStore;
use OCA\NcWireguard\Service\WireGuardKeys;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Load the WireGuard interface private key into the sealed store.
 *
 * Read from stdin by default so the key never lands in shell history, a
 * process list, or a CI log:
 *
 *   docker exec -i cloud_app php occ nc_wireguard:set-server-key < privatekey
 *
 * At cutover this is the **preserved** `wg0` key from
 * `/media/4TB/wireguard/config` — reusing it is what lets field peers keep
 * their existing configs. In the lab, `--generate` makes a fresh one.
 */
class SetServerKeyCommand extends Command
{
	public function __construct(
		private ServerKeyStore $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('nc_wireguard:set-server-key')
			->setDescription('Store the WireGuard interface private key (reads stdin)')
			->addOption(
				'generate',
				null,
				InputOption::VALUE_NONE,
				'Generate a new keypair instead of reading stdin (lab only — a new '
				. 'key invalidates every existing peer config)'
			)
			->addOption(
				'show-public',
				null,
				InputOption::VALUE_NONE,
				'Print the public key of the stored private key and exit'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if ((bool) $input->getOption('show-public')) {
			return $this->showPublic($output);
		}

		if ((bool) $input->getOption('generate')) {
			$keys = WireGuardKeys::generate();
			$private = $keys['private'];
			$output->writeln('<comment>Generated a NEW interface key — every existing peer '
				. 'config is now invalid and must be re-issued.</comment>');
		} else {
			$private = trim((string) file_get_contents('php://stdin'));
			if ($private === '') {
				$output->writeln('<error>No key on stdin. Pipe the private key in, or pass --generate.</error>');
				return Command::FAILURE;
			}
		}

		try {
			$public = $this->keys->store($private);
		} catch (Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		// The private key is never echoed; the public half is not a secret.
		$output->writeln('<info>Interface key stored. Public key: ' . $public . '</info>');
		$output->writeln('nc_wg_server.server_public_key updated to match.');
		return Command::SUCCESS;
	}

	private function showPublic(OutputInterface $output): int
	{
		try {
			$private = $this->keys->get();
		} catch (Throwable $e) {
			$output->writeln('<error>Stored key is unreadable: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}
		if ($private === null) {
			$output->writeln('<comment>No interface key stored.</comment>');
			return Command::SUCCESS;
		}
		$output->writeln(WireGuardKeys::publicFromPrivate($private));
		return Command::SUCCESS;
	}
}
