<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a split of the player's results says against their usual (docs/features/insights/computation.md "Core
 * sections" §6): a strength or weak spot only when the whole range lies beyond ±1.5 % and it rests on ≥ 10 results.
 */
enum InsightsVerdict: string
{
    case Strength = 'strength';
    case WeakSpot = 'weak_spot';
    case AboutUsual = 'about_usual';
    case NotSureYet = 'not_sure_yet';
}
