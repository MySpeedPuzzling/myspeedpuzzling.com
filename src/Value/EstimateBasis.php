<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a personal time estimate rests on (docs/features/insights/estimates.md) - the sheet's "What it rests on" line
 * and whether the time is marked as estimated:
 * - `picture`: nobody has solved the puzzle yet (k = 0) - your pace × the picture's difficulty estimate, shown only
 *   where ImageEstimateGate passes;
 * - `first_solves`: 1-4 difficulty indices - your pace × the shrunk difficulty (the statistical model);
 * - `measured`: 5+ indices - your pace × the measured difficulty;
 * - `your_solves`: you solved it before - the personal (repeat) prediction;
 * - `community`: you have no pace yet at any piece count - the community's usual time for the piece count, not
 *   personal (no tier, no window).
 */
enum EstimateBasis: string
{
    case Picture = 'picture';
    case FirstSolves = 'first_solves';
    case Measured = 'measured';
    case YourSolves = 'your_solves';
    case Community = 'community';

    /**
     * The time is an estimate from too little of the puzzle's own data: the dashed, "?" display
     */
    public function isEstimated(): bool
    {
        return $this === self::Picture || $this === self::FirstSolves;
    }

    public function isPersonal(): bool
    {
        return $this !== self::Community;
    }
}
