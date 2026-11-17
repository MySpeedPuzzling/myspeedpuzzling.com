<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the complete entrants of a `sum` standing are ordered (A-round-formats.md §3.3).
 */
enum StandingOrder: string
{
    // More rounds finished first, then the lower total - "999 of 1000 pieces" never beats a finished round (default)
    case FinishedRoundsFirst = 'finished_rounds_first';
    // The lower total only - organisers who publish "time limit + N s per missing piece" pick this
    case TotalOnly = 'total_only';
}
