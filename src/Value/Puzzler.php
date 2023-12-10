<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class Puzzler
{
    public function __construct(
        public null|string $playerId,
        public null|string $playerName,
    ) {
    }
}
