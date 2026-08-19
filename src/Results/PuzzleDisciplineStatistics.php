<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Community statistics of one puzzle in one discipline (solo, duo or team),
 * from the precomputed puzzle_statistics row. Seconds; null when nobody has
 * solved the puzzle in that discipline yet. Average and median are both over
 * each player's best time in the discipline (one value per player), so a
 * player who solved the puzzle ten times weighs the same as one who solved
 * it once; count is the number of recorded solves.
 */
readonly final class PuzzleDisciplineStatistics
{
    public function __construct(
        public int $count,
        public null|int $fastestSeconds,
        public null|int $averageSeconds,
        public null|int $slowestSeconds,
        public null|int $medianSeconds,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            count: 0,
            fastestSeconds: null,
            averageSeconds: null,
            slowestSeconds: null,
            medianSeconds: null,
        );
    }
}
