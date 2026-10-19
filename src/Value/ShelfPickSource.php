<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a shelf pick's expected time comes from: the statistical prediction of a rated puzzle, or the picture-only
 * estimate (`image`, marked as estimated) of a shelf puzzle nobody has solved yet where its gate passed
 * (ShelfPicks::estimates(), docs/features/insights/estimates.md).
 */
enum ShelfPickSource: string
{
    /** Your pace × the puzzle's difficulty (GetPlayerPredictions) */
    case Statistical = 'statistical';

    /** Your pace × the picture's difficulty estimate - nobody has solved the puzzle yet (ImageEstimateGate passed) */
    case Image = 'image';

    public function isEstimated(): bool
    {
        return $this === self::Image;
    }
}
