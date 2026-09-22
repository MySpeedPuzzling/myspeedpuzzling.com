<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class LendBorrowParticipant
{
    public function __construct(
        public null|string $playerId,
        public null|string $playerName,
        /** What to call the person in a flash / recap: the player's name (or code), or the typed name */
        public null|string $displayName = null,
    ) {
    }

    public function isRegistered(): bool
    {
        return $this->playerId !== null;
    }
}
