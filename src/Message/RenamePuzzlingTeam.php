<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RenamePuzzlingTeam
{
    public function __construct(
        public string $teamId,
        public string $playerId,
        // Empty or null takes the name away
        public null|string $name,
    ) {
    }
}
