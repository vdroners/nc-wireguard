<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

$now = new \DateTime('now', new \DateTimeZone('UTC'));
$fsm = \OC::$server->get(\OCA\NcWireguard\Service\ConnectionStateMachine::class);
$geo = \OC::$server->get(\OCA\NcWireguard\Service\GeoIpService::class);
$connMapper = \OC::$server->get(\OCA\NcWireguard\Db\ConnectionLogMapper::class);

$client = [
	'id' => 999,
	'name' => 'FSM-GATE-TEST',
	'endpoint' => '8.8.4.4:51820',
	'latestHandshakeAt' => $now->format('Y-m-d\TH:i:s\Z'),
];
$events = $fsm->transitionEvents($client, null, true);
foreach ($events as $event) {
	$row = new \OCA\NcWireguard\Db\ConnectionLog();
	$row->setTs($now);
	$row->setClientId(999);
	$row->setName('FSM-GATE-TEST');
	$row->setEvent($event['event']);
	$row->setEndpoint($event['endpoint']);
	$connMapper->insert($row);
	if ($event['event'] === 'connected' && $event['endpoint'] !== null) {
		$ip = \OCA\NcWireguard\Util\EndpointIp::parse($event['endpoint']);
		if ($ip !== null) {
			$geo->resolve($ip);
		}
	}
}
echo 'events=' . json_encode($events) . "\n";
