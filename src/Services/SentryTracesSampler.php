<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Sentry\Tracing\SamplingContext;
use Symfony\Component\HttpFoundation\RequestStack;

readonly final class SentryTracesSampler
{
    public function __construct(
        private string $profilingSecret,
        private float $defaultTracesSampleRate,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(): callable
    {
        return function (SamplingContext $context): float {
            $request = $this->requestStack->getCurrentRequest();

            if ($request === null) {
                return $this->defaultTracesSampleRate;
            }

            // Check query param for activation
            $queryValue = $request->query->get('_profile');
            $session = $request->hasSession() ? $request->getSession() : null;

            // Handle activation via secret
            if ($this->profilingSecret !== '' && $queryValue === $this->profilingSecret) {
                $session?->set('_sentry_profiler_enabled', $this->profilingSecret);

                return 1.0;
            }

            // Handle deactivation
            if ($queryValue === 'off') {
                $session?->remove('_sentry_profiler_enabled');

                return $this->defaultTracesSampleRate;
            }

            // Check session for persisted state - verify it matches the secret
            if ($this->profilingSecret !== '' && $session?->get('_sentry_profiler_enabled') === $this->profilingSecret) {
                return 1.0;
            }

            // Respect parent sampling decision
            if ($context->getParentSampled()) {
                return 1.0;
            }

            return $this->defaultTracesSampleRate;
        };
    }
}
