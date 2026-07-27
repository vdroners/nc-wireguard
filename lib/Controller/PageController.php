<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Controller;

use OCA\NcWireguard\AppInfo\Application;
use OCA\NcWireguard\Service\AppSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private AppSettings $appSettings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'nc_wireguard-main');
		Util::addStyle(Application::APP_ID, 'style');
		return new TemplateResponse(Application::APP_ID, 'main', [
			'enabled' => $this->appSettings->isDashboardEnabled(),
			'wg_easy_admin_url' => $this->appSettings->getWgEasyAdminUrl(),
			'hide_wg_easy_admin_link' => $this->appSettings->isWgEasyAdminLinkHidden(),
		]);
	}
}
