<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PuzzleAddMode;

readonly final class AddPuzzleToCollection
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public null|string $collectionId,
        public null|string $comment,
        // Sent only by the add page (docs/features/navigation README §4.6, ADD-01) - multiscan and the collection pages
        // leave it out, their saves never change the player's last add mode
        public null|PuzzleAddMode $rememberAddMode = null,
    ) {
    }
}
