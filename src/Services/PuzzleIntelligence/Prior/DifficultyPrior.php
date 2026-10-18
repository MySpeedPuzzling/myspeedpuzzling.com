<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One prior guess of a puzzle's difficulty before (or besides) its solves - the target of the shrunk difficulty of
 * puzzles with 1-4 indices, and the whole difficulty of a puzzle with none (README "Step 8", shrinkage). Priors form
 * a chain (DifficultyPriors): the first that knows the puzzle wins - today BrandPiecesPrior; Phase 5 of the Insights
 * plan puts ImageEstimatePrior in front of it (a higher #[AsTaggedItem] priority). Pure: everything comes in the
 * context.
 */
#[AutoconfigureTag]
interface DifficultyPrior
{
    public function priorFor(PriorContext $context): null|PriorEstimate;
}
