<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class AchievementHolder
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $countryCode,
        public null|string $avatar,
        public DateTimeImmutable $earnedAt,
        public null|BadgeTier $tier = null,
    ) {
    }
}
