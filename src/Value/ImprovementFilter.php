<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What "Puzzles needing improvement" lists: the need, the brand, only the viewer's collections (signed-in players),
 * and whether the viewer moderates the catalogue (admins + community moderators also get the puzzles whose image is not
 * the puzzle's picture at all).
 */
readonly final class ImprovementFilter
{
    public function __construct(
        public ImprovementNeed $need = ImprovementNeed::All,
        public null|string $manufacturerId = null,
        public null|string $collectionPlayerId = null,
        public bool $viewerModerates = false,
    ) {
    }
}
