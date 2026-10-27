<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the pace of a statistical prediction rests on (TimePredictionCalculator::baselineFor()): the player's own
 * baseline at the piece count (a direct one, with its drift), one interpolated from their other piece counts, or their
 * 500-piece pace scaled by the size exponent of a thin-size rule (prediction model v3). The time verification uses
 * only the first two - what it saw before v3.
 */
enum PaceSource: string
{
    case Own = 'own';
    case Interpolated = 'interpolated';
    case Scaled500 = 'scaled_500';
    // The candidate model's (v4) size curve (SizeCurve::pace()) - never a shown prediction's: other sizes only, other
    // sizes + 1-4 own first tries at the size, other sizes + the own direct pace
    case Pooled = 'pooled';
    case PooledFew = 'pooled_few';
    case PooledOwn = 'pooled_own';
}
