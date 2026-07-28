<?php

declare(strict_types=1);

namespace OCA\NcWireguard\AppInfo;

use OCA\NcWireguard\Listener\CspListener;
use OCA\NcWireguard\Listener\UninstallCleanupListener;
use OCA\NcWireguard\Service\EngineResolver;
use OCA\NcWireguard\Service\NativeEngine;
use OCA\NcWireguard\Service\WgEasyEngine;
use OCA\NcWireguard\Service\WireGuardEngineInterface;
use OCP\App\Events\AppUninstallEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'nc_wireguard';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// wg-easy stays the production engine. `EngineResolver` only hands over
		// to NativeEngine when the operator has set engine=native AND the peer
		// import is verified complete — see lib/Service/EngineResolver.php.
		$context->registerService(
			WireGuardEngineInterface::class,
			static function (ContainerInterface $container): WireGuardEngineInterface {
				return $container->get(EngineResolver::class)->useNative()
					? $container->get(NativeEngine::class)
					: $container->get(WgEasyEngine::class);
			}
		);

		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			CspListener::class
		);
		$context->registerEventListener(
			AppUninstallEvent::class,
			UninstallCleanupListener::class
		);
	}

	public function boot(IBootContext $context): void
	{
	}
}
