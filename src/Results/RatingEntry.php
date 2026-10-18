<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RatingPortfolio;

/**
 * One puzzle in one portfolio of a player's MSP rating, exactly as MspRatingCalculator scored it - stored as a row of
 * player_rating_entry (docs/features/insights/computation.md).
 */
readonly final class RatingEntry
{
    public function __construct(
        public string $puzzleId,
        public RatingPortfolio $portfolio,
        /** First attempt: the first-attempt time; best time: the best time */
        public int $seconds,
        /** What the decay counts from: the first attempt's moment, or the latest solve of the puzzle (best time) */
        public string $solvedAt,
        public float $percentile,
        public float $difficultyWeight,
        public float $decay,
        /** percentile × difficulty weight × decay */
        public float $points,
        /** Among the portfolio's best CAP entries, the ones the rating averages */
        public bool $inTop,
    ) {
    }
}
