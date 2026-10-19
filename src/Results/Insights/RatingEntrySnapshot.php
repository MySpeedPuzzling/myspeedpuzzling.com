<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * A stored player_rating_entry as the last batch run scored it.
 */
readonly final class RatingEntrySnapshot
{
    public function __construct(
        public int $seconds,
        public DateTimeImmutable $solvedAt,
        public float $percentile,
        public float $difficultyWeight,
        public float $points,
        public bool $inTop,
    ) {
    }

    /** percentile × difficulty weight - the points before decay */
    public function rawPoints(): float
    {
        return $this->percentile * $this->difficultyWeight;
    }
}
