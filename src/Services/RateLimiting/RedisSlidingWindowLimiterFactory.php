<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RateLimiting;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * A sliding-window rate limit whose count holds under concurrency - every consume() is one atomic Redis call
 * (RedisSlidingWindowLimiter). A drop-in for a `limiter.*` factory of `framework.rate_limiter`: the participant API of
 * online competitions counts with it in production (config/services.php), on the cache Redis - rate limits are
 * short-lived counters, never state that must survive (redis-state is the API usage counters' noeviction store).
 *
 * The key is hashed into the Redis key (`{prefix}{id}:{sha1(key)}`): a personal link's token never lands in Redis as
 * it is.
 */
final readonly class RedisSlidingWindowLimiterFactory implements RateLimiterFactoryInterface
{
    public function __construct(
        private Redis $redis,
        private string $id,
        private int $limit,
        private int $intervalSeconds,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $keyPrefix = 'rate_limit:',
    ) {
        if ($limit < 1 || $intervalSeconds < 1) {
            throw new \InvalidArgumentException('A rate limit needs a limit and an interval of at least 1.');
        }
    }

    public function create(null|string $key = null): LimiterInterface
    {
        return new RedisSlidingWindowLimiter(
            redis: $this->redis,
            redisKey: $this->keyPrefix . $this->id . ':' . sha1($key ?? ''),
            limit: $this->limit,
            intervalMs: $this->intervalSeconds * 1000,
            clock: $this->clock,
            logger: $this->logger,
        );
    }
}
