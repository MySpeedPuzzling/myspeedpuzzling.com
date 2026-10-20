<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One tile of "This week on the ladder" (GetRatingLadderThisWeek): the biggest climber, the best new entry or the most
 * active. $value = places gained (climber), puzzles solved for the rating (most active), null for a new entry.
 */
readonly final class RatingLadderSpotlight
{
    public const string CLIMBER = 'climber';
    public const string NEW_ENTRY = 'new_entry';
    public const string MOST_ACTIVE = 'most_active';

    public function __construct(
        public string $kind,
        public string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|string $playerCountry,
        public null|string $playerAvatar,
        public int $rank,
        public null|int $value,
    ) {
    }
}
