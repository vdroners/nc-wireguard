<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

/** @var \OCA\NcWireguard\Service\AppSettings $settings */
$settings = \OC::$server->get(\OCA\NcWireguard\Service\AppSettings::class);
$settings->setWgEasyApiUrl(getenv('WG_EASY_URL') ?: 'http://wg-easy:51821');
$settings->setWgEasyUsername(getenv('WG_EASY_USER') ?: 'admin');
$pass = getenv('WG_EASY_PASS') ?: '';
if ($pass !== '') {
	$settings->setWgEasyPassword($pass);
}
echo "wg_easy_api_url=" . $settings->getWgEasyApiUrl() . "\n";
echo "configured=" . ($settings->isWgEasyPasswordConfigured() ? 'yes' : 'no') . "\n";
