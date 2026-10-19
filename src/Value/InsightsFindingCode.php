<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one-sentence takeaways at the top of the Insights page (InsightsFinding). The sentences are Phase 6's
 * translation keys; the builder returns the code and its numbers.
 */
enum InsightsFindingCode: string
{
    /** value = share faster than the median solver (negative = slower), count = results */
    case Speed = 'speed';
    /** value = change of your median against a year ago (negative = faster now) */
    case FormTrend = 'form_trend';
    /** segment = your clearest strength */
    case Strength = 'strength';
    /** segment = your clearest weak spot */
    case WeakSpot = 'weak_spot';
    /** puzzleId, rank = the place within reach, seconds = what it takes */
    case CloseCall = 'close_call';
    /** puzzleId + timeId, value = share faster than your usual */
    case BestResult = 'best_result';
    /** count = results more than 10 % better than expected, puzzleId + timeId = the biggest (members) */
    case Surprises = 'surprises';
}
