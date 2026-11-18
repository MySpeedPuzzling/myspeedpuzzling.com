<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Is a round's analytics studio open somewhere (analytics-studio.md R23)? The state builder marks the round on every
 * state it computes (at most once per 2 s per round, AnalyticsStudioStateBuilder::TTL_SECONDS), the marker lives
 * WATCH_TTL_SECONDS in the shared pool `analytics_studio_watch_cache` - and AnalyticsStudioSignals publishes the public
 * studio signal only for a marked round. So a round nobody looks at in the studio publishes nothing new and runs no
 * statement for it: the question is one cache read.
 *
 * 180 s: the studio's fallback poll is 30 s ±20 % while its stream is live, 60 s idle - every poll after the 2 s state
 * cache expired re-marks; 180 s survives two lost polls and a background tab's throttled timers.
 *
 * Fail-closed: a pool that cannot answer means "not watched" (no signal - the studio's poll stays the safety net), a
 * marker that cannot be written never fails the state answer. No instance state (no ResetInterface needed).
 */
readonly final class AnalyticsStudioWatch
{
    public const int WATCH_TTL_SECONDS = 180;

    public function __construct(
        private CacheItemPoolInterface $analyticsStudioWatchCache,
    ) {
    }

    public static function key(string $roundId): string
    {
        return 'watch.' . strtolower($roundId);
    }

    /**
     * The staff marker (FU-ST): a staff state of the round was computed - its staff see the live ranking whatever the
     * live results setting, so its results writes signal while it is set.
     */
    public static function staffKey(string $roundId): string
    {
        return 'watch-staff.' . strtolower($roundId);
    }

    public function markWatched(string $roundId): void
    {
        $this->mark(self::key($roundId));
    }

    public function markStaffWatched(string $roundId): void
    {
        $this->mark(self::staffKey($roundId));
    }

    public function staffWatched(string $roundId): bool
    {
        try {
            return $this->analyticsStudioWatchCache->hasItem(self::staffKey($roundId));
        } catch (\Throwable) {
            return false;
        }
    }

    private function mark(string $key): void
    {
        try {
            $item = $this->analyticsStudioWatchCache->getItem($key);
            $item->set(1);
            $item->expiresAfter(self::WATCH_TTL_SECONDS);
            $this->analyticsStudioWatchCache->save($item);
        } catch (\Throwable) {
            // Fail-closed: without the marker the round gets no signals, its studio keeps polling
        }
    }

    public function watched(string $roundId): bool
    {
        try {
            return $this->analyticsStudioWatchCache->hasItem(self::key($roundId));
        } catch (\Throwable) {
            return false;
        }
    }
}
