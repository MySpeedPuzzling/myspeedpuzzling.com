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

    /**
     * @return \Closure(SamplingContext): float
     */
    public function __invoke(): \Closure
    {
        return function (SamplingContext $context): float {
            $request = $this->requestStack->getCurrentRequest();

            if ($request === null) {
                return $this->defaultTracesSampleRate;
            }

            // Check query param for activation
            $queryValue = $request->query->get('_profile');

            // Handle activation via secret - deliberately starts a session to persist the flag
            if ($this->profilingSecret !== '' && $queryValue === $this->profilingSecret) {
                if ($request->hasSession()) {
                    $request->getSession()->set('_sentry_profiler_enabled', $this->profilingSecret);
                }

                return 1.0;
            }

            // This sampler runs on every request; reading a not-yet-started session would
            // start it, stamping Set-Cookie + Cache-Control: private on every anonymous
            // response and persisting a session row per page view. Only touch existing ones.
            $session = $request->hasPreviousSession() ? $request->getSession() : null;

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
