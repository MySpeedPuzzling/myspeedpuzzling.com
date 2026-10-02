<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which line-up a comparison works on (docs/features/player-comparison.md). Like is only ever compared with like:
 * players' solo times, exact pairs, or exact teams of three or more - never mixed.
 */
enum ComparisonKind: string
{
    case Solo = 'solo';
    case Pairs = 'pairs';
    case Teams = 'teams';

    public static function forTeamSize(int $size): self
    {
        return $size <= 2 ? self::Pairs : self::Teams;
    }
}
