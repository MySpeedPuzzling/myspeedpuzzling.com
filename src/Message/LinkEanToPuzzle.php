<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A member scanned a code the catalogue does not know and picked the puzzle it
 * belongs to (docs/features/multiscan/README.md §6, linking policy).
 */
readonly final class LinkEanToPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $playerId,
        public string $ean,
    ) {
    }
}
