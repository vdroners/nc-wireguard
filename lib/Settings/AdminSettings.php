<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Settings;

use OCA\NcWireguard\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	public function __construct(
		private IConfig $config,
	) {
	}

	public function getForm(): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'nc_wireguard-admin');
		Util::addStyle(Application::APP_ID, 'style');
		return new TemplateResponse(Application::APP_ID, 'admin_settings', [], '');
	}

	public function getSection(): string
	{
		return Application::APP_ID;
	}

	public function getPriority(): int
	{
		return 10;
	}
}
