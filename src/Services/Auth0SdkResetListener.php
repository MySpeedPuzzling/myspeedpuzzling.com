<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Auth0\Symfony\Service;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets Auth0 SDK at the start of each request.
 *
 * This is critical for FrankenPHP worker mode where PHP processes persist between requests.
 * Without this reset, the Auth0 SDK caches user credentials in memory, causing session leakage
 * where one user's authentication bleeds into another user's request.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final class Auth0SdkResetListener
{
    public function __construct(
        #[Autowire(service: 'auth0')]
        private readonly Service $auth0Service,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        // Reset Auth0 SDK to force fresh credentials check from session
        $reflection = new \ReflectionClass($this->auth0Service);
        $property = $reflection->getProperty('sdk');
        $property->setValue($this->auth0Service, null);
    }
}
