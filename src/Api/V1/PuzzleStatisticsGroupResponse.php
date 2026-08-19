<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PuzzleDisciplineStatistics;

/**
 * Community statistics of a puzzle in one discipline. Seconds; null when
 * nobody has solved the puzzle in that discipline yet. average_seconds and
 * median_seconds are over each player's best time in the discipline (the
 * same population), rounded to whole seconds.
 */
final class PuzzleStatisticsGroupResponse
{
    public function __construct(
        public int $count,
        public null|int $fastestSeconds,
        public null|int $averageSeconds,
        public null|int $slowestSeconds,
        public null|int $medianSeconds,
    ) {
    }

    public static function fromResult(PuzzleDisciplineStatistics $statistics): self
    {
        return new self(
            count: $statistics->count,
            fastestSeconds: $statistics->fastestSeconds,
            averageSeconds: $statistics->averageSeconds,
            slowestSeconds: $statistics->slowestSeconds,
            medianSeconds: $statistics->medianSeconds,
        );
    }
}
