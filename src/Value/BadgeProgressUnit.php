<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the numbers in a BadgeProgress mean, so the UI can print "37 / 100" for counters
 * and a formatted time for the speed achievements (where LOWER is better).
 */
enum BadgeProgressUnit: string
{
    case Count = 'count';
    case Seconds = 'seconds';
}
