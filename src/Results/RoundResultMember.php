<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class RoundResultMember
{
    public function __construct(
        public string $participantId,
        public string $name,
        public null|CountryCode $country,
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public bool $isPrivate,
    ) {
    }
}
