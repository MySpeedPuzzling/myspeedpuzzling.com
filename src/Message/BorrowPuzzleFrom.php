<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class BorrowPuzzleFrom
{
    public function __construct(
        public UuidInterface $borrowingId,
        public string $puzzleId,
        public string $borrowerId, // who is borrowing
        public null|string $ownerId, // null if borrowing from non-registered person
        public null|string $nonRegisteredPersonName, // name if not registered
    ) {
    }
}