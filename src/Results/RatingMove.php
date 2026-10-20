<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\MspRatingTimeline;

/**
 * A ladder row's movement against the stored day about a week ago (docs/features/msp-rating-history.md): places gained
 * (negative = lost, ranked in the same list as the row) and the rating points change - null when a formula change lies
 * between the two days. "New" = not on that list then.
 */
readonly final class RatingMove
{
    public function __construct(
        // The stored day compared with - a week ago, or a later formula change day (MspRatingTimeline::moveReferenceDay())
        public DateTimeImmutable $since,
        public bool $isNew,
        public null|int $placesGained = null,
        public null|int $pointsChange = null,
    ) {
    }

    public static function between(
        int $rank,
        float $rating,
        null|int $previousRank,
        null|float $previousRating,
        DateTimeImmutable $referenceDay,
        DateTimeImmutable $now,
    ): self {
        if ($previousRank === null || $previousRating === null) {
            return new self(since: $referenceDay, isNew: true);
        }

        $pointsChange = MspRatingTimeline::pointsComparable($referenceDay, $now)
            ? (int) round($rating * 1000) - (int) round($previousRating * 1000)
            : null;

        return new self(
            since: $referenceDay,
            isNew: false,
            placesGained: $previousRank - $rank,
            pointsChange: $pointsChange,
        );
    }
}
