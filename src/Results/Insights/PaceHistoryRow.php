<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * One row of the player's pace history (player_pace_history): the pace after the qualifying first attempt that moved it.
 */
readonly final class PaceHistoryRow
{
    public function __construct(
        public int $piecesCount,
        public DateTimeImmutable $validFrom,
        public int $baselineSeconds,
        /** The solve's position among the player's qualifying first attempts at the piece count (5 = first row) */
        public int $qualifyingCount,
        /** ln(baseline ÷ previous baseline); null on the first row */
        public null|float $step,
        public string $timeId,
        public int $solveSeconds,
        /** null = the puzzle is kept secret now */
        public null|InsightsPuzzleRef $puzzle,
    ) {
    }
}
