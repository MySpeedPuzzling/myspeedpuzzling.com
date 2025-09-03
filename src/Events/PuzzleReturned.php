<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleReturned
{
    public function __construct(
        public UuidInterface $puzzleId,
        public UuidInterface $ownerId,
        public null|UuidInterface $borrowerId,
        public UuidInterface $initiatorId,
    ) {
    }
}
