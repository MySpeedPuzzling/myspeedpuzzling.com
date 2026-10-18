<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * A solved puzzle that does not count in the rating only because it has fewer than 20 public first-attempt solvers.
 */
readonly final class PendingQualification
{
    public function __construct(
        public InsightsPuzzleRef $puzzle,
        public int $solverCount,
        public int $solversNeeded,
        /** Rating points (× 1000) the player's entries of it would add once it counts, at today's percentile */
        public float $gainWhenItCounts,
    ) {
    }
}
