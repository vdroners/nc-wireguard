<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

$config = \OC::$server->get(\OCP\IConfig::class);
$dataDir = (string) $config->getSystemValue('datadirectory', sys_get_temp_dir());
$lockPath = rtrim($dataDir, '/') . '/nc_wireguard_poll.lock';
$fp = fopen($lockPath, 'c');
if ($fp === false) {
	fwrite(STDERR, "open failed\n");
	exit(1);
}
if (!flock($fp, LOCK_EX)) {
	fwrite(STDERR, "hold lock failed\n");
	exit(1);
}

$cmd = 'php /var/www/html/occ nc_wireguard:poll-metrics 2>&1';
exec($cmd, $out, $code);
echo implode("\n", $out) . "\n";
echo 'exit=' . $code . "\n";
flock($fp, LOCK_UN);
fclose($fp);
