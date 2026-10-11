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
        /** Earned by the launch backfill: its date means nothing - "at launch" (design-spec §8.1) */
        public bool $heldAtLaunch = false,
        /** The very first holder of the tier, after launch - the "First to earn" trophy (design-spec §8.2) */
        public bool $firstToEarn = false,
    ) {
    }
}
