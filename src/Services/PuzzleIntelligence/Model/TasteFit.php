<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;
use SplFixedArray;

/**
 * What TasteFitter returns: the model (null = not fitted - too few first tries) and, by TasteRows row, the term and the
 * variance (vᵀ A⁻¹ v) each first try had as of its moment.
 */
final readonly class TasteFit
{
    /**
     * @param null|SplFixedArray<float> $terms
     * @param null|SplFixedArray<float> $variances
     * @param array<string, int|float|string> $summary
     * @param array<int, TasteState> $queryStates by query (TasteFitter::fit()'s $queries)
     */
    public function __construct(
        public null|TasteModel $model,
        public null|SplFixedArray $terms,
        public null|SplFixedArray $variances,
        public array $summary,
        public array $queryStates = [],
    ) {
    }
}
