<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a shelf pick's expected time comes from. Phase 5d adds the picture-only estimate (`image`, members, marked
 * as estimated) for shelf puzzles nobody has solved yet - ShelfPicks::estimates() is the seam.
 */
enum ShelfPickSource: string
{
    /** Your pace × the puzzle's difficulty (GetPlayerPredictions) */
    case Statistical = 'statistical';
}
