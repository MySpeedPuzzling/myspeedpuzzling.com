<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Starts the Sentry runtime context at the very beginning of each request,
 * before the routing and security listeners run.
 *
 * sentry-symfony's own RuntimeContextListener starts the context at kernel.request
 * priority 6 — after the router (32) and the firewall (8). Records logged before
 * that point ("Matched route", security authenticator logs) would become
 * breadcrumbs on the shared base hub, whose scope is cloned into every new
 * context: a cross-request breadcrumb leak on persistent FrankenPHP workers.
 *
 * Starting the context here closes that window without overriding any vendor
 * wiring: the context manager treats the bundle listener's later startContext()
 * as a no-op, and endContext() is idempotent, so the terminate handler and
 * reset() below are safety nets that only act if the bundle stopped doing it.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'startContext', priority: 512)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'endContext', priority: -256)]
final readonly class SentryEarlyRuntimeContextListener implements ResetInterface
{
    /**
     * The HubInterface dependency makes the container initialize and configure
     * the Sentry hub before the first startContext() call.
     */
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function startContext(RequestEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        SentrySdk::startContext();

        if ($this->hub->getClient() === null) {
            $this->logger->error('Sentry client is null at request start - error tracking will not work');
        }
    }

    public function endContext(TerminateEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        SentrySdk::endContext();
    }

    public function reset(): void
    {
        SentrySdk::endContext();
    }
}
