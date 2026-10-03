<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * The difficulty tier on the thumbnails of a puzzle list (_difficulty_corner.html.twig) is members-only, and the
 * viewer decides, never whoever the list belongs to. For everyone else nothing is queried and the answer is null -
 * the one switch templates read. One query per list, however long (GetPuzzleDifficulty::tiersOf()).
 */
readonly final class ResolveDifficultyTiers
{
    public function __construct(
        private GetPuzzleDifficulty $getPuzzleDifficulty,
    ) {
    }

    /**
     * @param array<string> $puzzleIds duplicates are fine
     *
     * @return null|array<string, DifficultyTier> null = no tiers for this viewer; a puzzle missing from the array is
     *                                            not rated yet
     */
    public function forViewer(null|PlayerProfile $viewer, array $puzzleIds): null|array
    {
        if ($viewer?->activeMembership !== true) {
            return null;
        }

        return $this->getPuzzleDifficulty->tiersOf($puzzleIds);
    }
}
