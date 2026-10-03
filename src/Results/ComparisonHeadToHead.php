<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * The head-to-head card of the highlighted pair: "N puzzles you both solved · X is N % faster on the median puzzle"
 * plus the wins split bar. Counted over the shown puzzles both solved.
 */
readonly final class ComparisonHeadToHead
{
    public function __construct(
        public ComparisonSubjectRef $a,
        public ComparisonSubjectRef $b,
        public int $winsA,
        public int $winsB,
        public int $ties,
        public int $shared,
        // Null when nothing is shared or the median puzzle is a dead heat
        public null|ComparisonSubjectRef $faster,
        // How much less time the faster one needs on the median puzzle (geometric median of the time ratios), e.g. 12.5
        public null|float $fasterPercent,
    ) {
    }
}
