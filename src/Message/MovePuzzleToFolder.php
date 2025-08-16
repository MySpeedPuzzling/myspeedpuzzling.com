<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class MovePuzzleToFolder
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public null|string $folderId = null,
    ) {
    }
}