<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class LendPuzzleToPlayer
{
    public function __construct(
        public string $ownerPlayerId,
        public string $puzzleId,
        public string $borrowerPlayerId,
        public null|string $notes = null,
    ) {
    }
}
