<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class LendBorrowParticipant
{
    public function __construct(
        public null|string $playerId,
        public null|string $playerName,
    ) {
    }

    public function isRegistered(): bool
    {
        return $this->playerId !== null;
    }
}
