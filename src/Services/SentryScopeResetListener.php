<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets Sentry scope at the start of each request.
 *
 * This is critical for FrankenPHP worker mode where PHP processes persist between requests.
 * Without this reset, the Sentry scope accumulates state (breadcrumbs, tags, user data, contexts)
 * from previous requests, causing data leakage between users.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class SentryScopeResetListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope): void {
            // Clear all accumulated state: breadcrumbs, tags, contexts, extra, user, fingerprint, level, span, flags
            $scope->clear();

            // Reset propagation context to get fresh trace/span IDs for this request
            $scope->setPropagationContext(PropagationContext::fromDefaults());
        });
    }
}
