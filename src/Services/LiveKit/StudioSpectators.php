<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\LiveKitRequestFailed;
use SpeedPuzzling\Web\Value\LiveKitIdentity;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * The spectators of a round's studio and a place for one more (docs/features/online-competitions/contracts/
 * phase-1b.md §5 `online_watch_token`). Until Phase 4 brings HLS every spectator is a WebRTC subscriber of the studio
 * room, so the watch page lets in a limited number of them (OnlineWatchTokenController).
 *
 * - The count is LiveKit's own: the studio room's `s:*` participants (LiveKitApi::listParticipants()), asked at most
 *   once per COUNT_TTL_SECONDS per studio and kept in the `online_watch_spectators` pool. Every place handed out
 *   meanwhile adds one, so a crowd arriving within those seconds cannot pass the limit before LiveKit has seen it
 *   join; the next listing replaces the estimate with the truth (a token that never joined frees its place again).
 * - One admission at a time per studio: read, compare, add and store run under a lock on the cache Redis (the
 *   `online_watch` lock, config/packages/rate_limiter.php) - parallel requests never all take the last free place.
 *   Without the lock (the cache Redis unreachable) the admission still happens, unlocked: watching never depends on
 *   the cache.
 * - A studio room that does not exist yet holds nobody. LiveKit unreachable or refusing: the spectator is let in (the
 *   page cannot connect then anyway and keeps trying) and the failure is kept for the same few seconds - a dead media
 *   server is asked once per window, not once per spectator.
 */
final readonly class StudioSpectators
{
    public const int COUNT_TTL_SECONDS = 5;

    // Longer than a listing may take (LiveKitApi::MAX_DURATION_SECONDS): a holder that died never blocks for long
    private const float LOCK_TTL_SECONDS = 10.0;
    // An admission holds the lock for a few Redis round trips (a listing once per window): waiting for it is retried
    // every few milliseconds - the lock's own blocking wait sleeps 100 ms per try - and given up after a listing's
    // longest time and a bit, counted unlocked then
    private const int LOCK_WAIT_MS = 4000;
    private const int LOCK_RETRY_MS = 8;

    public function __construct(
        private LiveKitApi $liveKitApi,
        #[Autowire(service: 'online_watch_spectators')]
        private CacheItemPoolInterface $cache,
        #[Autowire(service: 'lock.online_watch.factory')]
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A place for one more spectator in the round's studio - false when it holds $limit of them already (then nothing
     * is counted)
     */
    public function admit(string $roundId, int $limit): bool
    {
        $roundId = strtolower($roundId);
        $lock = $this->lock($roundId);

        try {
            $item = $this->cache->getItem(self::key($roundId));
            $nowMs = RoundClock::milliseconds($this->clock->now());
            $entry = $item->isHit() ? self::entry($item->get(), $nowMs) : null;

            if ($entry === null) {
                $entry = ['count' => $this->listed($roundId), 'listedAtMs' => $nowMs];
            }

            if ($entry['count'] >= $limit) {
                // A fresh listing is kept for the next ones asking - a full studio is listed once per window too
                $this->store($item, $entry, $nowMs);

                return false;
            }

            $this->store($item, ['count' => $entry['count'] + 1, 'listedAtMs' => $entry['listedAtMs']], $nowMs);

            return true;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (LockException) {
                    // Expires by itself (LOCK_TTL_SECONDS)
                }
            }
        }
    }

    private static function key(string $roundId): string
    {
        return 'studio_spectators_' . $roundId;
    }

    /**
     * The lock of the studio's count - null when the lock store cannot be reached or the lock stays taken (counted
     * unlocked then)
     */
    private function lock(string $roundId): null|LockInterface
    {
        $lock = $this->lockFactory->createLock('online-watch-studio-' . $roundId, self::LOCK_TTL_SECONDS);
        $deadline = microtime(true) + self::LOCK_WAIT_MS / 1000;

        try {
            while ($lock->acquire() === false) {
                if (microtime(true) >= $deadline) {
                    $this->logger->warning('The studio\'s spectator count stayed locked - counting without the lock', [
                        'round_id' => $roundId,
                    ]);

                    return null;
                }

                usleep(random_int(self::LOCK_RETRY_MS / 2, self::LOCK_RETRY_MS * 2) * 1000);
            }

            return $lock;
        } catch (LockException $e) {
            $this->logger->warning('The studio\'s spectator count runs without its lock', [
                'round_id' => $roundId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * A stored count still within its window - null for anything else
     *
     * @return null|array{count: int, listedAtMs: int}
     */
    private static function entry(mixed $value, int $nowMs): null|array
    {
        if (!is_array($value) || !is_int($value['count'] ?? null) || !is_int($value['listedAtMs'] ?? null)) {
            return null;
        }

        $listedAtMs = $value['listedAtMs'];

        // The pool rounds its expiry to whole seconds - the window is kept here to the millisecond
        if ($listedAtMs > $nowMs || $nowMs - $listedAtMs >= self::COUNT_TTL_SECONDS * 1000) {
            return null;
        }

        return ['count' => max(0, $value['count']), 'listedAtMs' => $listedAtMs];
    }

    /**
     * @param array{count: int, listedAtMs: int} $entry
     */
    private function store(CacheItemInterface $item, array $entry, int $nowMs): void
    {
        $leftMs = $entry['listedAtMs'] + self::COUNT_TTL_SECONDS * 1000 - $nowMs;

        $item->set($entry);
        $item->expiresAfter(max(1, (int) ceil($leftMs / 1000)));
        $this->cache->save($item);
    }

    /**
     * The spectators LiveKit lists in the studio room now - 0 for a room not opened yet, and 0 when LiveKit cannot say
     */
    private function listed(string $roundId): int
    {
        try {
            $participants = $this->liveKitApi->listParticipants(LiveKitRoomKind::Studio->roomName($roundId));
        } catch (LiveKitRequestFailed $e) {
            if ($e->isNotFound() === false) {
                $this->logger->warning('Could not list the spectators of a studio - letting them in', [
                    'round_id' => $roundId,
                    'exception' => $e,
                ]);
            }

            return 0;
        }

        $spectators = 0;

        foreach ($participants as $participant) {
            $identity = $participant['identity'] ?? null;

            if (is_string($identity) && str_starts_with($identity, LiveKitIdentity::SPECTATOR_PREFIX)) {
                $spectators++;
            }
        }

        return $spectators;
    }
}
