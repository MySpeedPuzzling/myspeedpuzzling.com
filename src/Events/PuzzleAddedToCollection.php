<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleAddedToCollection
{
    public function __construct(
        public UuidInterface $collectionItemId,
        public string $playerId,
        public string $puzzleId,
    ) {
    }
}
