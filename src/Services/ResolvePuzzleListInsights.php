<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetPuzzleListInsights;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzleListInsights;

/**
 * The viewer decides, never the list owner: a member browsing somebody else's
 * public collection sees tiers, a non-member browsing a member's does not.
 * Difficulty is members-only, so for everyone else it is not even queried.
 */
readonly final class ResolvePuzzleListInsights
{
    public function __construct(
        private GetPuzzleListInsights $getPuzzleListInsights,
    ) {
    }

    /**
     * @param array<string> $puzzleIds the puzzles listed on the page (duplicates are fine)
     */
    public function forViewer(null|PlayerProfile $viewer, array $puzzleIds): PuzzleListInsights
    {
        $withDifficulty = $viewer?->activeMembership === true;

        return new PuzzleListInsights(
            byPuzzle: $this->getPuzzleListInsights->forPuzzles($puzzleIds, $withDifficulty),
            withDifficulty: $withDifficulty,
        );
    }
}
