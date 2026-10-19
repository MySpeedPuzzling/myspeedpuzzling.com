<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use SpeedPuzzling\Web\Value\DifficultySource;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The puzzle's picture-based difficulty estimate (PuzzleDifficultyEstimate, the image pipeline's nightly ridge model -
 * Insights plan §5.3/§5.4) as the prior of the shrunk difficulty: in front of BrandPiecesPrior, so a puzzle with an
 * estimate of its current picture shrinks toward it and every other one falls through to brand × pieces.
 *
 * Answers only when the context carries an estimate (PriorContext::$imageEstimate), and only the side-by-side
 * predictors' contexts do: `blend` (the statistical model shrunk toward this prior) and `image` (the prior alone).
 * The statistical predictor - the shown one - never sees it until the estimates pass their gates (ImageEstimateGate).
 * Fades like every prior as solves arrive: weight τ ÷ (k + τ), with the fitted τ ≈ 0.5 about 14 % at k = 3 and gone
 * from k = 5 on (the median of the solves).
 *
 * A prediction resting on it alone gets the difficulty source `image` and the estimate's own interval as the puzzle
 * term of its window (ImagePriorInput::variance()).
 */
#[AsTaggedItem(priority: 10)]
readonly final class ImageEstimatePrior implements DifficultyPrior
{
    public const string NAME = 'image_estimate';

    public function priorFor(PriorContext $context): null|PriorEstimate
    {
        $estimate = $context->imageEstimate;

        if ($estimate === null) {
            return null;
        }

        return new PriorEstimate($estimate->logDifficulty, self::NAME, DifficultySource::Image, $estimate->variance());
    }
}
