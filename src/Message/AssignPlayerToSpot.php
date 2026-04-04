<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AssignPlayerToSpot
{
    public function __construct(
        public string $spotId,
        public null|string $playerId = null,
        public null|string $playerName = null,
    ) {
    }
}
