<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleRemovedFromCollection
{
    public function __construct(
        public UuidInterface $puzzleId,
        public null|UuidInterface $collectionId,
        public UuidInterface $playerId,
        public null|string $systemType,
    ) {
    }
}