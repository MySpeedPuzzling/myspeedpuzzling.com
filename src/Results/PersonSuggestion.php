<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PersonSuggestion
{
    public function __construct(
        // Player id, or "g:" + normalised guest name
        public string $key,
        // What the form submits in group_players[]: "#CODE" for a player, the name for a guest
        public string $value,
        public string $label,
        public null|string $playerId,
        public null|string $playerCode,
        public null|CountryCode $country,
        public null|string $avatar,
        // Times together in any pair/team, and as just the two of us
        public int $timesCount,
        public int $pairTimesCount,
        public null|DateTimeImmutable $lastTogetherAt,
        public null|DateTimeImmutable $pairLastTogetherAt,
        public float $score,
        public float $pairScore,
        public bool $isFavorite,
        // A private player hidden from the viewer - only their code is known (the add form takes them by code; the
        // partner finder's co-member picker leaves them out, PartnerListingRules would refuse them)
        public bool $isHidden = false,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->playerId === null;
    }
}
