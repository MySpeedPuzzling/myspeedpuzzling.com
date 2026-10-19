<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior;

/**
 * What the difficulty priors of one puzzle may use - fetched together with the prediction's other inputs (one
 * statement on the live path), so a prior itself never queries. A prior that needs more (Phase 5: the image
 * estimate) adds its field here and to the statements that build the context.
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
    ) {
    }
}
