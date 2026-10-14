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
 * exists - then the second store, until the failures age out of the window AND the store answers a probe again.
 *
 * A failure is a store's own error: a phone's PUT answered 408, 429 or 5xx - or not answered at all (status 0: in a
 * browser a store that is down looks like that, its error pages carry no CORS headers; a PUT the phone aborted after its
 * timeout too) - which the phone reports with its next presign request (`previousFailure`, countsAsStoreFailure()), or
 * the server's HEAD failing (VerifyOnlineMedia). Sources are counted, not failures - the participant id of a phone,
 * SERVER for the server - so one participant's phone can never move everybody's uploads. A refused URL (400 / 403) or a
 * phone its browser knows to be offline is no store failure.
 *
 * Closing again: a store that opened the breaker is "tripped" (TRIPPED_SECONDS at most). Once its failures aged out,
 * the next request asks the store itself (OnlineMediaStorage::probe() - one HEAD through the server's client, one
 * request at a time: PROBE_LOCK_SECONDS) before sending uploads back to it: an answer closes the breaker, none keeps it
 * open another WINDOW_SECONDS ("held") - so a store still down costs one probe a minute instead of a wave of failed
 * uploads (spikes.md S5: the breaker flapped once a minute). While another request probes, the breaker stays open. The
 * probe runs inside a phone's presign request: at most ~6 s once a minute (the S3 client's timeouts), never more.
 *
 * The counters and marks live in `redis-state` (a sorted set per store, source => last failure ms, expiring after
 * 2 × WINDOW_SECONDS; `:tripped`, `:held`, `:probing` next to it). Without a second store nothing is counted, probed or
 * asked - every upload goes to the primary, and Redis is never touched (tests, and production until the second store
 * exists). A Redis error never stops an upload: it counts as "closed" (the primary).
 */
final readonly class OnlineStoreBreaker
{
    public const int FAILURES_TO_OPEN = 3;
    public const int WINDOW_SECONDS = 60;
    // A tripped store is probed before it is trusted again - unless nothing asked for this long
    public const int TRIPPED_SECONDS = 86_400;
    public const int PROBE_LOCK_SECONDS = 15;
    public const string SERVER = 'server';

    // KEYS: failures, tripped. ARGV: now ms, source, window start ms, failures TTL s, sources to open, tripped TTL s
    private const string RECORD_SCRIPT = <<<'LUA'
redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[3])
redis.call('EXPIRE', KEYS[1], ARGV[4])
local sources = redis.call('ZCARD', KEYS[1])
if sources >= tonumber(ARGV[5]) then
    redis.call('SET', KEYS[2], ARGV[1], 'EX', ARGV[6])
end
return sources
LUA;

    // KEYS: failures, tripped, held. ARGV: window start (exclusive, `(ms`). Returns {sources in the window, tripped, held}
    private const string READ_SCRIPT = <<<'LUA'
return {
    redis.call('ZCOUNT', KEYS[1], ARGV[1], '+inf'),
    redis.call('EXISTS', KEYS[2]),
    redis.call('EXISTS', KEYS[3])
}
LUA;

    public function __construct(
        private Redis $redis,
        private OnlineMediaStores $stores,
        private OnlineMediaStorage $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $keyPrefix = 'online:store_failures:',
    ) {
    }

    /**
     * A phone's upload answered with this status counts as the store failing: a timeout, a busy store, a server error,
     * or no answer at all (0 - the phone's browser was online, the store did not answer: refused, unreachable, an error
     * without CORS headers, or an upload the phone aborted after its timeout). 400 / 403 and the rest are a URL the store
     * refused - no sign of a store being down.
     */
    public static function countsAsStoreFailure(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
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
            $sources = $this->scriptInt(self::RECORD_SCRIPT, [
                // KEYS
                $this->key($store),
                $this->key($store) . ':tripped',
                // ARGV
                (string) $nowMs,
                strtolower($source),
                (string) ($nowMs - self::WINDOW_SECONDS * 1000),
                (string) (2 * self::WINDOW_SECONDS),
                (string) self::FAILURES_TO_OPEN,
                (string) self::TRIPPED_SECONDS,
            ], 2);
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
     * The store failed for enough sources lately - or it did, and has not answered a probe since (always false without
     * a second store)
     *
     * @phpstan-impure it may probe the store and change the marks
     */
    public function isOpen(OnlineMediaStore $store): bool
    {
        if ($this->stores->hasSecondary() === false) {
            return false;
        }

        $sinceMs = RoundClock::milliseconds($this->clock->now()) - self::WINDOW_SECONDS * 1000;

        try {
            $state = $this->script(self::READ_SCRIPT, [
                $this->key($store),
                $this->key($store) . ':tripped',
                $this->key($store) . ':held',
                '(' . $sinceMs,
            ], 3);
        } catch (\Throwable $e) {
            $this->logger->warning('Reading the online media store failures failed', [
                'store' => $store->value,
                'exception' => $e,
            ]);

            return false;
        }

        [$sources, $tripped, $held] = is_array($state) && count($state) === 3 ? array_values($state) : [0, 0, 0];

        if (is_int($sources) && $sources >= self::FAILURES_TO_OPEN) {
            return true;
        }

        if ($tripped !== 1) {
            return false;
        }

        // Its failures aged out - the store answers again before uploads go back to it
        return $held === 1 || $this->stillFailsItsProbe($store);
    }

    /**
     * The store a new upload goes to
     *
     * @phpstan-impure it may probe the store and change the marks
     */
    public function storeForNewUpload(): OnlineMediaStore
    {
        if ($this->isOpen(OnlineMediaStore::Primary) && $this->isOpen(OnlineMediaStore::Secondary) === false) {
            return OnlineMediaStore::Secondary;
        }

        return OnlineMediaStore::Primary;
    }

    /**
     * One request probes at a time - while another one does, the breaker stays open. An answer closes it (the tripped
     * mark goes), none holds it open another WINDOW_SECONDS.
     */
    private function stillFailsItsProbe(OnlineMediaStore $store): bool
    {
        $key = $this->key($store);

        try {
            if ($this->redis->set($key . ':probing', '1', ['nx', 'ex' => self::PROBE_LOCK_SECONDS]) !== true) {
                return true;
            }

            if ($this->storage->probe($store)) {
                $this->redis->del($key . ':tripped', $key . ':held', $key . ':probing');
                $this->logger->info('Online media store answers its probe again - new uploads go to it', [
                    'store' => $store->value,
                ]);

                return false;
            }

            $this->redis->set($key . ':held', '1', ['ex' => self::WINDOW_SECONDS]);
            $this->redis->del($key . ':probing');
        } catch (\Throwable $e) {
            $this->logger->warning('Probing an online media store failed', [
                'store' => $store->value,
                'exception' => $e,
            ]);

            return false;
        }

        $this->logger->info('Online media store still fails its probe - new uploads stay on the other store', [
            'store' => $store->value,
        ]);

        return true;
    }

    private function key(OnlineMediaStore $store): string
    {
        return $this->keyPrefix . $store->value;
    }

    /**
     * @param list<string> $arguments the keys first
     */
    private function scriptInt(string $script, array $arguments, int $keys): int
    {
        $result = $this->script($script, $arguments, $keys);

        if (!is_int($result)) {
            throw new RuntimeException('Online media store failures script answered no number');
        }

        return $result;
    }

    /**
     * @param list<string> $arguments the keys first
     */
    private function script(string $script, array $arguments, int $keys): mixed
    {
        $result = $this->redis->evalSha(sha1($script), $arguments, $keys);

        if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
            // Redis restarted (or was never asked before) - EVAL loads the script into its cache
            $this->redis->clearLastError();
            $result = $this->redis->eval($script, $arguments, $keys);
        }

        if ($result === false) {
            $error = $this->redis->getLastError();
            $this->redis->clearLastError();

            throw new RuntimeException('Online media store failures script failed: ' . ($error ?? 'unknown Redis error'));
        }

        return $result;
    }
}
