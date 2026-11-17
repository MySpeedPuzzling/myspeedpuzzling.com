<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The public display endpoints reached by a display token (the page, its state and its design - D-displays-overlays.md
 * §9.1, competition toolkit G3): `display_public` per token and client IP (config/packages/rate_limiter.php). Generous
 * on purpose - a hall's screens share one address and one link - so it stops only hammering. Over the limit: 429 with
 * Retry-After and nothing read; the display keeps its last picture and comes back after Retry-After
 * (assets/displays/runtime.js). Logged at info: a client's loop, not something for a person to look at (no token in the
 * log - it is the access right).
 */
readonly final class CompetitionDisplayRateLimit
{
    public function __construct(
        private RateLimiterFactoryInterface $displayPublicLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return null|Response null = go ahead, else the 429 to answer with
     */
    public function refusal(Request $request, string $token): null|Response
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $limit = $this->displayPublicLimiter->create(self::key($token, $ip))->consume();

        if ($limit->isAccepted()) {
            return null;
        }

        // @phpstan-ignore clock.systemTime (the limiter counts on the real clock - its retry-after is the real time to wait)
        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        $this->logger->info('A competition display link was asked too often - answered 429.', [
            'ip' => $ip,
            'retryAfter' => $retryAfter,
        ]);

        return new Response('', Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $retryAfter,
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public static function key(string $token, string $ip): string
    {
        return $token . '|' . $ip;
    }
}
