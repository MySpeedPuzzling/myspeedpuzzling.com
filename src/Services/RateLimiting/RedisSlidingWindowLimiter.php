<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RateLimiting;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\RateLimiter\Exception\ReserveNotSupportedException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;

/**
 * One key of a sliding-window rate limit, counted in ONE atomic Redis call (RedisSlidingWindowLimiterFactory) - read,
 * decide and count happen inside a Lua script, so requests arriving at the same moment are counted one after another
 * and never more than the limit is accepted. Symfony's own limiters read the window, decide in PHP and write it back:
 * without a lock two requests read the same count, and its lock on Redis (RedisStore, no blocking support) makes every
 * waiting request sleep in 100 ms steps while holding a PHP worker - see docs/features/online-competitions/README.md
 * §6 for the measurements.
 *
 * The window is Symfony's sliding window (SlidingWindow): the hits of the current window plus the previous window's,
 * weighted by how much of it still overlaps - floor(previous × (1 − elapsed / interval) + hits). A refused request is
 * not counted. Moments are the app's clock in epoch ms (the web containers share the host's clock); the hash expires
 * two intervals after its window started. A Redis that does not answer lets the request through - the limiter protects
 * the site, it must never be what takes it down - and says so in the log.
 */
final readonly class RedisSlidingWindowLimiter implements LimiterInterface
{
    // KEYS[1] = the key's hash {start, hits, previous}; ARGV = now (ms), interval (ms), limit, tokens (0 = only look).
    // Returns {accepted 0|1, hits counted after this call, window start (ms), hits of the window, hits of the previous}
    private const string CONSUME_SCRIPT = <<<'LUA'
local now = tonumber(ARGV[1])
local interval = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
local tokens = tonumber(ARGV[4])
local stored = redis.call('HMGET', KEYS[1], 'start', 'hits', 'previous')
local start = tonumber(stored[1])
local hits = tonumber(stored[2]) or 0
local previous = tonumber(stored[3]) or 0

if start == nil then
    start = now
    hits = 0
    previous = 0
elseif now < start then
    -- Another web container's clock a little behind: no moment before the window
    now = start
end

if now >= start + interval then
    if now < start + 2 * interval then
        previous = hits
        start = start + interval
    else
        previous = 0
        start = now
    end
    hits = 0
end

local weight = 1 - (now - start) / interval
local counted = math.floor(previous * weight + hits)
local accepted = 0

if tokens > 0 and counted + tokens <= limit then
    hits = hits + tokens
    counted = math.floor(previous * weight + hits)
    accepted = 1
end

if tokens > 0 then
    redis.call('HSET', KEYS[1], 'start', string.format('%.0f', start), 'hits', hits, 'previous', previous)
    redis.call('PEXPIRE', KEYS[1], math.max(1, start + 2 * interval - now))
end

return {accepted, counted, string.format('%.0f', start), hits, previous}
LUA;

    public function __construct(
        private Redis $redis,
        private string $redisKey,
        private int $limit,
        private int $intervalMs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function consume(int $tokens = 1): RateLimit
    {
        if ($tokens < 0 || $tokens > $this->limit) {
            throw new \InvalidArgumentException(sprintf('Cannot consume %d tokens of a rate limiter of %d.', $tokens, $this->limit));
        }

        $nowMs = (int) $this->clock->now()->format('Uv');

        try {
            [$accepted, $counted, $startMs, $hits, $previous] = $this->run($nowMs, $tokens);
        } catch (\Throwable $exception) {
            // A Redis that does not answer: RedisException, or the lazy connection's InvalidArgumentException
            $this->logger->warning('A rate limit could not be counted - the request goes through', [
                'exception' => $exception,
            ]);

            return new RateLimit($this->limit, self::second($nowMs), true, $this->limit);
        }

        $available = max(0, $this->limit - $counted);

        if ($accepted || $tokens === 0) {
            return new RateLimit($available, self::second($nowMs), true, $this->limit);
        }

        return new RateLimit($available, self::second($nowMs + $this->waitMs($nowMs, $startMs, $hits, $previous, $tokens), up: true), false, $this->limit);
    }

    public function reserve(int $tokens = 1, null|float $maxTime = null): Reservation
    {
        throw new ReserveNotSupportedException(self::class);
    }

    public function reset(): void
    {
        $this->redis->del($this->redisKey);
    }

    /**
     * @return array{bool, int, int, int, int} accepted, counted, window start (ms), hits, previous hits
     */
    private function run(int $nowMs, int $tokens): array
    {
        $arguments = [$this->redisKey, (string) $nowMs, (string) $this->intervalMs, (string) $this->limit, (string) $tokens];
        $result = $this->redis->evalSha(sha1(self::CONSUME_SCRIPT), $arguments, 1);

        if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
            // Redis restarted (or was never asked before) - EVAL loads the script into its cache
            $this->redis->clearLastError();
            $result = $this->redis->eval(self::CONSUME_SCRIPT, $arguments, 1);
        }

        if (!is_array($result) || count($result) !== 5) {
            $error = $this->redis->getLastError();
            $this->redis->clearLastError();

            throw new \RuntimeException('Counting a rate limit failed: ' . ($error ?? 'unexpected answer'));
        }

        $values = array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, array_values($result));

        return [$values[0] === 1, $values[1], $values[2], $values[3], $values[4]];
    }

    /**
     * How long until `tokens` more fit: while the previous window's weight fades within this window, or - when this
     * window's own hits are too many already - into the next one, where they are the previous window's
     */
    private function waitMs(int $nowMs, int $startMs, int $hits, int $previous, int $tokens): int
    {
        $room = $this->limit - $tokens;

        if ($hits <= $room && $previous > 0) {
            $fraction = 1 - ($room - $hits) / $previous;

            return max(0, $startMs + (int) ceil($fraction * $this->intervalMs) - $nowMs);
        }

        $fraction = $hits <= 0 ? 0.0 : max(0.0, 1 - $room / $hits);

        return max(0, $startMs + $this->intervalMs + (int) ceil($fraction * $this->intervalMs) - $nowMs);
    }

    private static function second(int $ms, bool $up = false): DateTimeImmutable
    {
        $seconds = $up ? (int) ceil($ms / 1000) : intdiv($ms, 1000);

        return new DateTimeImmutable('@' . $seconds);
    }
}
