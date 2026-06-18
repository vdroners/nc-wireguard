<?php

declare(strict_types=1);

namespace OCA\NcWireguard\AppInfo;

use OCA\NcGcs\Util\ThemeAssetLoader;
use OCA\NcWireguard\Listener\CspListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'nc_wireguard';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			CspListener::class
		);
	}

	public function boot(IBootContext $context): void
	{
		if (class_exists(ThemeAssetLoader::class)) {
			ThemeAssetLoader::register(self::APP_ID);
		}
	}
}
