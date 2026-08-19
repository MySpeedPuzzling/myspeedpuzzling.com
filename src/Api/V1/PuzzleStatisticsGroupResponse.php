<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PuzzleDisciplineStatistics;

/**
 * Community statistics of a puzzle in one discipline. Seconds; null when
 * nobody has solved the puzzle in that discipline yet.
 */
final class PuzzleStatisticsGroupResponse
{
    public function __construct(
        public int $count,
        public null|int $fastest_seconds,
        public null|int $average_seconds,
        public null|int $slowest_seconds,
    ) {
    }

    public static function fromResult(PuzzleDisciplineStatistics $statistics): self
    {
        return new self(
            count: $statistics->count,
            fastest_seconds: $statistics->fastestSeconds,
            average_seconds: $statistics->averageSeconds,
            slowest_seconds: $statistics->slowestSeconds,
        );
    }
}
