<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The three futures of the rating forecast (RatingForecast).
 */
enum RatingForecastScenario: string
{
    /** No new results: entries age (decay) and leave the 24-month window */
    case Stop = 'stop';
    /** The last 6 months' rated first tries again, one sixth of them a month */
    case KeepPace = 'keep_pace';
    /** Keeping the pace plus the 10 best moves (RatingOpportunities) done in the first month */
    case BestMoves = 'best_moves';
}
