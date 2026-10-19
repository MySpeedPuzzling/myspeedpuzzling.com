<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

use SpeedPuzzling\Web\Value\ImagePriorInput;

/**
 * What the difficulty priors of one puzzle may use - fetched together with the prediction's other inputs (one
 * statement on the live path), so a prior itself never queries.
 *
 * The image estimate (ImageEstimatePrior) is set only on the contexts of the side-by-side `image` / `blend`
 * predictors (withImageEstimate()) - the statistical predictor, the shown one, never gets it, so it keeps shrinking
 * toward the brand prior until the estimates have passed their gates (Insights plan §5.4, ImageEstimateGate).
 */
readonly final class PriorContext
{
    public function __construct(
        public string $puzzleId,
        public int $piecesCount,
        public null|string $manufacturerId = null,
        // BrandPiecesPrior: the sums of the puzzle's brand × piece-count group as of the last fit, and the puzzle's own
        // share in them (left out - a puzzle never shrinks toward itself); 0 for a puzzle the fit did not see
        public float $brandPiecesGroupSumLog = 0.0,
        public int $brandPiecesGroupCount = 0,
        public float $brandPiecesOwnSumLog = 0.0,
        public int $brandPiecesOwnCount = 0,
        // ImageEstimatePrior: the current picture's estimate - out-of-fold only for a reconstruction; null for the
        // statistical predictor and for secret puzzles
        public null|ImagePriorInput $imageEstimate = null,
    ) {
    }

    public function withImageEstimate(null|ImagePriorInput $imageEstimate): self
    {
        return new self(
            $this->puzzleId,
            $this->piecesCount,
            $this->manufacturerId,
            $this->brandPiecesGroupSumLog,
            $this->brandPiecesGroupCount,
            $this->brandPiecesOwnSumLog,
            $this->brandPiecesOwnCount,
            $imageEstimate,
        );
    }
}
