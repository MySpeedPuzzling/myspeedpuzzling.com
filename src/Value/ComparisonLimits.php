<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How many subjects one line-up (per kind) holds (docs/features/player-comparison.md). Yourself included when you are
 * in it. Free players compare yourself + 1 other in Solo, and 2 pairs or 2 teams.
 */
final readonly class ComparisonLimits
{
    public const int MEMBER = 10;
    public const int FREE = 2;

    public static function forMembership(bool $isMember): int
    {
        return $isMember ? self::MEMBER : self::FREE;
    }
}
