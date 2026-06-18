<?php

declare(strict_types=1);

namespace OCA\NcWireguard\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * @implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener
{
	public function handle(Event $event): void
	{
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}
		$csp = new EmptyContentSecurityPolicy();
		$csp->addAllowedImageDomain('*.basemaps.cartocdn.com');
		$csp->addAllowedImageDomain('*.cartocdn.com');
		$csp->addAllowedConnectDomain('*.basemaps.cartocdn.com');
		$csp->addAllowedConnectDomain('*.cartocdn.com');
		$csp->addAllowedImageDomain('blob:');
		$csp->addAllowedConnectDomain('blob:');
		$event->addPolicy($csp);
	}
}
