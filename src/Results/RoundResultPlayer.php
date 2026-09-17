<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class RoundResultPlayer
{
    public function __construct(
        // Null for a co-puzzler typed in by name, without a MySpeedPuzzling profile
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|CountryCode $playerCountry,
        public bool $isPrivate,
        public null|string $skillTierName,
        public bool $rankingOptedOut,
    ) {
    }
}
