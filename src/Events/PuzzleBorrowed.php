<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleBorrowed
{
    public function __construct(
        public UuidInterface $puzzleId,
        public UuidInterface $fromPlayerId,
        public UuidInterface $toPlayerId,
        public null|string $nonRegisteredPersonName,
        public bool $borrowedFrom, // true = borrowedFrom, false = borrowedTo
    ) {
    }
}