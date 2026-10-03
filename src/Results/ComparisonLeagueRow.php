<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One line of the league table (3+ subjects): # · subject · Wins · Solved · Gap. Counted over the shown puzzles.
 */
readonly final class ComparisonLeagueRow
{
    public function __construct(
        public int $position,
        public ComparisonSubjectRef $subject,
        // Puzzles where the subject is the unique fastest of ≥ 2 solvers
        public int $wins,
        public int $solved,
        // Puzzles solved together with at least one other subject - what the gap is the median of
        public int $compared,
        // Median of (time / fastest time − 1) over the compared puzzles: 0.12 = 12 % behind the fastest; null = none compared
        public null|float $gap,
        // "You" (or a pair/team you are in) - the tinted row
        public bool $isSelf,
    ) {
    }
}
