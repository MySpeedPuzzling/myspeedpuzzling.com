<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use Symfony\UX\Chartjs\Model\Chart;

/**
 * Everything the rating card and the profile's rating block show (Services\MspRating\RatingStoryBuilder): today's place
 * and rating on the ladder, the movement the ladder shows for the player, the history and its two charts.
 */
readonly final class RatingStory
{
    public function __construct(
        public int $rank,
        public int $total,
        public int $rating,
        public null|RatingMove $move,
        public RatingHistory $history,
        public null|Chart $ratingChart,
        public null|Chart $placeChart,
        // A formula change lies inside the history - the charts mark it, the note under them explains the line
        public bool $showsFormulaChange = false,
    ) {
    }
}
