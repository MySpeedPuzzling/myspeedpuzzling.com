<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Pair/team coordination constants c(size, first try or repeat): how much longer a group takes than its members'
 * solo paces combined like rates predict (expected = c × difficulty ÷ Σ 1/baselineᵢ). Fitted daily into
 * group_coordination_constant; these seeds - measured on the production copy 2026-10 - stand in while a bucket has
 * fewer than MINIMUM_SAMPLES results (docs/features/insights/computation.md).
 */
final class GroupCoordination
{
    public const int MINIMUM_SAMPLES = 30;

    /** Group sizes: 2 = pair, 3 = team of three, 4 = four or more */
    public const array SIZES = [2, 3, 4];

    /** @var array<int, array{first: float, repeat: float}> */
    public const array SEEDS = [
        2 => ['first' => 1.21, 'repeat' => 1.08],
        3 => ['first' => 1.35, 'repeat' => 1.24],
        4 => ['first' => 1.41, 'repeat' => 1.32],
    ];

    public static function sizeGroup(int $members): int
    {
        return max(2, min(4, $members));
    }

    public static function seed(int $members, bool $firstAttempt): float
    {
        return self::SEEDS[self::sizeGroup($members)][$firstAttempt ? 'first' : 'repeat'];
    }
}
