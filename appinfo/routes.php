<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
		['name' => 'settings#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
		['name' => 'settings#testConnection', 'url' => '/api/settings/test', 'verb' => 'POST'],
		['name' => 'settings#testWgEasy', 'url' => '/api/settings/test-wg-easy', 'verb' => 'POST'],
		['name' => 'dashboard#proxy', 'url' => '/api/dashboard/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],

		// Peer read (v2.1 naming + legacy alias kept for cached frontend bundles).
		['name' => 'wg_easy_read_proxy#peerConfiguration', 'url' => '/api/peers/{clientId}/configuration', 'verb' => 'GET', 'requirements' => ['clientId' => '\d+']],
		['name' => 'wg_easy_read_proxy#configuration', 'url' => '/api/wg-easy/{clientId}/configuration', 'verb' => 'GET', 'requirements' => ['clientId' => '\d+']],

		// Peer write (v2.1+). CSRF on mutating routes; OTL redeem is PublicPage.
		['name' => 'peer_write#create', 'url' => '/api/peers', 'verb' => 'POST'],
		['name' => 'peer_write#update', 'url' => '/api/peers/{clientId}', 'verb' => 'POST', 'requirements' => ['clientId' => '\d+']],
		['name' => 'peer_write#destroy', 'url' => '/api/peers/{clientId}', 'verb' => 'DELETE', 'requirements' => ['clientId' => '\d+']],
		['name' => 'peer_write#enable', 'url' => '/api/peers/{clientId}/enable', 'verb' => 'POST', 'requirements' => ['clientId' => '\d+']],
		['name' => 'peer_write#disable', 'url' => '/api/peers/{clientId}/disable', 'verb' => 'POST', 'requirements' => ['clientId' => '\d+']],
		['name' => 'peer_write#oneTimeLink', 'url' => '/api/peers/{clientId}/one-time-link', 'verb' => 'POST', 'requirements' => ['clientId' => '\d+']],
		['name' => 'peer_write#redeemOtl', 'url' => '/api/peers/otl/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9_-]+']],
	],
];
