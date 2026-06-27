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
		['name' => 'dashboard_proxy#proxy', 'url' => '/api/dashboard/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'wg_easy_read_proxy#configuration', 'url' => '/api/wg-easy/{clientId}/configuration', 'verb' => 'GET', 'requirements' => ['clientId' => '\d+']],
	],
];
