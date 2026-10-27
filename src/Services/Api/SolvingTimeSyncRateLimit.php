<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * One budget per token owner for the sync checks (lookup + deleted, docs/features/api/solving-time-sync.md).
 */
readonly final class SolvingTimeSyncRateLimit
{
    public function __construct(
        private Security $security,
        private RateLimiterFactoryInterface $apiSolvingTimeSyncLimiter,
    ) {
    }

    public function consume(): void
    {
        $key = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';
        $limit = $this->apiSolvingTimeSyncLimiter->create($key)->consume();

        if ($limit->isAccepted()) {
            return;
        }

        // @phpstan-ignore clock.systemTime (rate-limiter retry-after: the limiter counts on the system clock)
        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $retryAfterSeconds,
            sprintf('Too many sync checks. Try again in %d seconds.', $retryAfterSeconds),
        );
    }
}
