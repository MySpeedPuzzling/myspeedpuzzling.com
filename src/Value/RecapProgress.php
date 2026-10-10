<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The rules of the progress card on the recap after logging a time (docs/features/xp-levels/launch-2026-10.md,
 * "After logging a time").
 */
final class RecapProgress
{
    /**
     * Only a recap opened right after the save celebrates (level-up motion, the milestone sheet). An old recap URL - back
     * from editing the time, a bookmark - shows the same card without any of it. Reloads and back navigation inside this
     * window are stopped in the browser (recap_celebration_controller.js remembers the time ids it celebrated).
     */
    public const int CELEBRATE_WITHIN_MINUTES = 60;

    /** Level-ups that get the bottom sheet; every other level-up is the lighter celebration on the card */
    public const array MILESTONE_LEVELS = [10, 20, 30, 40, 50];

    /** The puzzle page shows "faster than N %" from this many rows on (PuzzleTimes) */
    public const int PERCENTILE_MIN_PLAYERS = 10;

    public static function isFresh(DateTimeImmutable $trackedAt, DateTimeImmutable $now): bool
    {
        return $trackedAt >= $now->modify(sprintf('-%d minutes', self::CELEBRATE_WITHIN_MINUTES));
    }

    public static function isMilestoneLevel(int $level): bool
    {
        return in_array($level, self::MILESTONE_LEVELS, true);
    }

    /**
     * Share of the other puzzlers with a slower best time, rounded down - null when there are too few to say, or when
     * the player is first (the card says so instead). Ties count as faster, so it never overstates.
     */
    public static function fasterThanPercent(int $rank, int $total): null|int
    {
        if ($total < self::PERCENTILE_MIN_PLAYERS || $rank <= 1) {
            return null;
        }

        $percent = intdiv(100 * ($total - $rank), $total - 1);

        return $percent > 0 ? $percent : null;
    }
}
