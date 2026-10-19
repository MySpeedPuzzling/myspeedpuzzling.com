<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which results count: all, first tries only or repeats only (members).
 */
enum InsightsResultsFilter: string
{
    case All = 'all';
    case FirstTries = 'first';
    case Repeats = 'repeat';
}
