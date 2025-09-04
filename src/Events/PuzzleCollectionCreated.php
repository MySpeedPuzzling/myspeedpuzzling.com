<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzleCollectionCreated
{
    public function __construct(
        public UuidInterface $collectionId,
        public UuidInterface $playerId,
        public string $collectionName,
    ) {
    }
}
