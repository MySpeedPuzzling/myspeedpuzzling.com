<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "How far am I from the ladder?" - shown only while it is within reach (README: path onto the ladder only where
 * useful).
 */
readonly final class LadderPath
{
    public function __construct(
        /** First attempts on puzzles that count, in the 24-month window, of the 20 needed */
        public int $firstTries,
        public int $firstTriesNeeded,
        /** Puzzles of the piece count solved in the window, of the 50 needed */
        public int $results,
        public int $resultsNeeded,
        /** New results that would get the player there (first tries count for both) */
        public int $newResultsNeeded,
        /** What the entries are worth today (× 1000), and the place that would be on today's ladder */
        public float $ratingWhenOnLadder,
        public int $placeWhenOnLadder,
    ) {
    }
}
