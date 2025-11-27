<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

readonly final class PuzzleBorrowed
{
    public function __construct(
        public string $borrowerPlayerId,
        public string $puzzleId,
    ) {
    }
}
