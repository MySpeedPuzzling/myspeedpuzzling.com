<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddPuzzleToCollection
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public null|string $collectionId,
        public null|string $comment,
    ) {
    }
}
