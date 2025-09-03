<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class AddPuzzleToCollection
{
    public function __construct(
        public UuidInterface $itemId,
        public string $puzzleId,
        public null|string $collectionId, // null means root collection
        public string $playerId,
        public null|string $comment = null,
        public null|string $price = null,
        public null|string $condition = null,
    ) {
    }
}