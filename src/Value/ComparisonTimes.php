<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which time of a subject on a puzzle is compared: the best one, or the first try only (members, D3). The filter applies
 * to the times before they are aggregated - "best" is the best within the other filters.
 */
enum ComparisonTimes: string
{
    case Best = 'best';
    case FirstTries = 'first';
}
