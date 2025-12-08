<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[AsEventListener(priority: 1024)]
readonly final class DebugToolbarListener
{
    public function __construct(
        private string $profilingSecret,
        private null|Profiler $profiler,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        if ($this->profiler === null) {
            return;
        }

        $request = $event->getRequest();
        $queryValue = $request->query->get('_debug');
        $session = $request->hasSession() ? $request->getSession() : null;

        // Handle activation via secret
        if ($this->profilingSecret !== '' && $queryValue === $this->profilingSecret) {
            $session?->set('_profiler_enabled', $this->profilingSecret);
            $this->profiler->enable();

            return;
        }

        // Handle deactivation
        if ($queryValue === 'off') {
            $session?->remove('_profiler_enabled');
            // Profiler is disabled by default (collect: false in config)
            return;
        }

        // Check session for persisted state - verify it matches the secret
        if ($this->profilingSecret !== '' && $session?->get('_profiler_enabled') === $this->profilingSecret) {
            $this->profiler->enable();
        }
    }
}
