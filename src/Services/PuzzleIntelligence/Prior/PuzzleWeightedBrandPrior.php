<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

/**
 * The candidate model's (v4) brand × pieces prior (review P8): the mean over the group's puzzles of each puzzle's mean
 * log index - every puzzle counts once - shrunk toward the average puzzle with a fitted pseudo-count of puzzles, the
 * puzzle itself left out. BrandPiecesPrior (the shown model's) averages over indices, so a few popular, easy puzzles
 * dominate it: at k = 0 its predictions came 4.1 % too fast on average (time-model review F3).
 *
 * Not in the prior chain (DifficultyPriors) - the candidate calculator asks it directly; its value per puzzle is stored
 * by the candidate's daily fit (candidate_puzzle_prior) for the live path.
 */
final readonly class PuzzleWeightedBrandPrior
{
    public const string NAME = 'brand_pieces';
    public const int MINIMUM_OTHER_PUZZLES = 2;

    /**
     * @param float $groupMeanSum Σ over the group's puzzles of their mean log index
     * @param int $groupPuzzles the group's puzzles with an index
     * @param null|float $ownMean the puzzle's own mean log index when it is one of them (left out)
     */
    public static function fromSums(float $groupMeanSum, int $groupPuzzles, null|float $ownMean, float $pseudoPuzzles): null|float
    {
        $others = $groupPuzzles - ($ownMean !== null ? 1 : 0);

        if ($others < self::MINIMUM_OTHER_PUZZLES) {
            return null;
        }

        return ($groupMeanSum - ($ownMean ?? 0.0)) / ($others + $pseudoPuzzles);
    }
}
