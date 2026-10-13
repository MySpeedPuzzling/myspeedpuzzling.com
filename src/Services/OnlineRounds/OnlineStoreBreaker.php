<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * Which store a new evidence upload goes to (docs/features/online-competitions/README.md §5.11, the circuit breaker):
 * the primary, unless it failed for FAILURES_TO_OPEN different sources within WINDOW_SECONDS while a second store
 * exists - then the second store, until the failures age out of the window (the next uploads try the primary again,
 * and a primary still down opens the breaker again).
 *
 * A failure is a store's own error: a phone's PUT answered 408, 429 or 5xx (the phone reports it with its next presign
 * request, `previousFailure`) or the server's HEAD failing (VerifyOnlineMedia). Sources are counted, not failures - the
 * participant id of a phone, SERVER for the server - so one participant's phone can never move everybody's uploads.
 * A refused URL (400 / 403) or a phone that is offline is no store failure.
 *
 * The counters live in `redis-state` (a sorted set per store, source => last failure ms, expiring after
 * 2 × WINDOW_SECONDS). Without a second store nothing is counted and nothing is asked - every upload goes to the primary,
 * and Redis is never touched (tests, and production until the second store exists). A Redis error never stops an
 * upload: it counts as "closed" (the primary).
 */
final readonly class OnlineStoreBreaker
{
    public const int FAILURES_TO_OPEN = 3;
    public const int WINDOW_SECONDS = 60;
    public const string SERVER = 'server';

    private const string RECORD_SCRIPT = <<<'LUA'
redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[3])
redis.call('EXPIRE', KEYS[1], ARGV[4])
return redis.call('ZCARD', KEYS[1])
LUA;

    public function __construct(
        private Redis $redis,
        private OnlineMediaStores $stores,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $keyPrefix = 'online:store_failures:',
    ) {
    }

    /**
     * A store failed for this source (a participant id, or SERVER)
     */
    public function recordFailure(OnlineMediaStore $store, string $source): void
    {
        if ($this->stores->hasSecondary() === false) {
            return;
        }

        $nowMs = RoundClock::milliseconds($this->clock->now());

        try {
            $sources = $this->script(self::RECORD_SCRIPT, [
                // KEYS
                $this->key($store),
                // ARGV
                (string) $nowMs,
                strtolower($source),
                (string) ($nowMs - self::WINDOW_SECONDS * 1000),
                (string) (2 * self::WINDOW_SECONDS),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Recording an online media store failure failed', [
                'store' => $store->value,
                'exception' => $e,
            ]);

            return;
        }

        if ($sources === self::FAILURES_TO_OPEN) {
            // Once per opening - a person should know the store is failing
            $this->logger->warning('Online media store failing - new uploads go to the other store', [
                'store' => $store->value,
                'sources' => $sources,
            ]);
        }
    }

    /**
     * The store failed for enough sources lately (always false without a second store)
     */
    public function isOpen(OnlineMediaStore $store): bool
    {
        if ($this->stores->hasSecondary() === false) {
            return false;
        }

        $sinceMs = RoundClock::milliseconds($this->clock->now()) - self::WINDOW_SECONDS * 1000;

        try {
            $sources = $this->redis->zCount($this->key($store), '(' . $sinceMs, '+inf');
        } catch (\Throwable $e) {
            $this->logger->warning('Reading the online media store failures failed', [
                'store' => $store->value,
                'exception' => $e,
            ]);

            return false;
        }

        return is_int($sources) && $sources >= self::FAILURES_TO_OPEN;
    }

    /**
     * The store a new upload goes to
     */
    public function storeForNewUpload(): OnlineMediaStore
    {
        if ($this->isOpen(OnlineMediaStore::Primary) && $this->isOpen(OnlineMediaStore::Secondary) === false) {
            return OnlineMediaStore::Secondary;
        }

        return OnlineMediaStore::Primary;
    }

    private function key(OnlineMediaStore $store): string
    {
        return $this->keyPrefix . $store->value;
    }

    /**
     * @param list<string> $arguments the key first
     */
    private function script(string $script, array $arguments): int
    {
        $result = $this->redis->evalSha(sha1($script), $arguments, 1);

        if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
            // Redis restarted (or was never asked before) - EVAL loads the script into its cache
            $this->redis->clearLastError();
            $result = $this->redis->eval($script, $arguments, 1);
        }

        if (!is_int($result)) {
            $error = $this->redis->getLastError();
            $this->redis->clearLastError();

            throw new RuntimeException('Online media store failures script failed: ' . ($error ?? 'unknown Redis error'));
        }

        return $result;
    }
}
