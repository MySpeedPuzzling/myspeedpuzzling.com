<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One bar of the leaderboard distribution chart: the rows whose time is in [from, to).
 * An open end (null) is a folded tail - every row faster than `to`, or slower than `from`.
 */
readonly final class LeaderboardHistogramBin
{
    public function __construct(
        public null|int $from,
        public null|int $to,
        public int $count,
        // Of $count, the rows whose time is a first attempt
        public int $firstAttempts = 0,
    ) {
    }

    public function repeats(): int
    {
        return $this->count - $this->firstAttempts;
    }

    public function isFoldedTail(): bool
    {
        return $this->from === null || $this->to === null;
    }
}
