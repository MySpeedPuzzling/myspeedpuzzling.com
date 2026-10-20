<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One player's MSP Rating day by day (player_rating_snapshot, the day's last values) for the rating card and the
 * profile (docs/features/msp-rating-history.md): the rating on the ladder's display scale and the stored world place
 * (null on a day the player was rated but not on the ladder).
 */
readonly final class RatingHistory
{
    /**
     * @param list<array{date: DateTimeImmutable, rating: int, place: null|int}> $days oldest first
     */
    public function __construct(
        public array $days,
        public null|int $bestPlace,
        public null|DateTimeImmutable $bestPlaceOn,
        public int $daysOnLadder,
    ) {
    }

    /**
     * @param list<array{pieces_count: int, date: DateTimeImmutable, rating: float, place: null|int}> $rows GetPlayerRatingSnapshots::forPlayer(), one piece count
     */
    public static function fromSnapshots(array $rows): self
    {
        $days = [];
        $bestPlace = null;
        $bestPlaceOn = null;
        $daysOnLadder = 0;

        foreach ($rows as $row) {
            $days[] = ['date' => $row['date'], 'rating' => (int) round($row['rating'] * 1000), 'place' => $row['place']];

            if ($row['place'] === null) {
                continue;
            }

            $daysOnLadder++;

            // The first day the best place was reached
            if ($bestPlace === null || $row['place'] < $bestPlace) {
                $bestPlace = $row['place'];
                $bestPlaceOn = $row['date'];
            }
        }

        return new self($days, $bestPlace, $bestPlaceOn, $daysOnLadder);
    }

    /**
     * The rating stored on the last day up to $day - what a points change compares with
     */
    public function ratingOn(DateTimeImmutable $day): null|int
    {
        $rating = null;

        foreach ($this->days as $stored) {
            if ($stored['date'] > $day) {
                break;
            }

            $rating = $stored['rating'];
        }

        return $rating;
    }
}
