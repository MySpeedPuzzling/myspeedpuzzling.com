<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class TableLayoutSpot
{
    public function __construct(
        public string $id,
        public int $position,
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|CountryCode $playerCountry,
    ) {
    }

    public function isAssigned(): bool
    {
        return $this->playerId !== null || $this->playerName !== null;
    }
}
