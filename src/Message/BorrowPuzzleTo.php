<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class BorrowPuzzleTo
{
    public function __construct(
        public UuidInterface $borrowingId,
        public string $puzzleId,
        public string $ownerId,
        public null|string $borrowerId, // null if non-registered person
        public null|string $nonRegisteredPersonName, // name if not registered
        public bool $returnExistingBorrowing = true,
    ) {
    }
}
