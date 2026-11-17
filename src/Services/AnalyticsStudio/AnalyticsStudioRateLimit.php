<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The analytics studio's heavy answers (analytics, entrant, predictions) per client IP (analytics-studio.md R6,
 * `analytics_studio_heavy`) - far above a commentator's tabs, a stop for a scraper walking every round. `state` has no
 * limit: it is micro-cached and a venue's screens share one address.
 */
readonly final class AnalyticsStudioRateLimit
{
    public function __construct(
        private RateLimiterFactoryInterface $analyticsStudioHeavyLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException 429 with Retry-After
     */
    public function consume(Request $request): void
    {
        $limit = $this->analyticsStudioHeavyLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if ($limit->isAccepted() === false) {
            // @phpstan-ignore clock.systemTime (the limiter counts on the real clock - its retry-after is the real time to wait)
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new TooManyRequestsHttpException($retryAfter);
        }
    }
}
