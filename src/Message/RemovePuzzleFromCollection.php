<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RemovePuzzleFromCollection
{
    public function __construct(
        public string $puzzleId,
        public string $collectionId,
        public string $playerId,
    ) {
    }
}