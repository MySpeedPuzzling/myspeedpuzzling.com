<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One prior guess of a puzzle's difficulty before (or besides) its solves - the target of the shrunk difficulty of
 * puzzles with 1-4 indices, and the whole difficulty of a puzzle with none (README "Step 8", shrinkage). Priors form
 * a chain (DifficultyPriors): the first that knows the puzzle wins - ImageEstimatePrior (priority 10) in front of
 * BrandPiecesPrior (0). Pure: everything comes in the context, and the context decides which priors can answer (only
 * the side-by-side image / blend predictors' contexts carry an image estimate).
 */
#[AutoconfigureTag]
interface DifficultyPrior
{
    public function priorFor(PriorContext $context): null|PriorEstimate;
}
