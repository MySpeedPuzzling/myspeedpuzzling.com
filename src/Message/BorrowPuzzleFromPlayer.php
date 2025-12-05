<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class BorrowPuzzleFromPlayer
{
    public function __construct(
        public string $borrowerPlayerId,
        public string $puzzleId,
        public null|string $ownerPlayerId = null,
        public null|string $ownerName = null,
        public null|string $notes = null,
    ) {
    }
}
