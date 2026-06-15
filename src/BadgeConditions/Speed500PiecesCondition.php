<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * Tier qualifies when the player's best 500pc SOLO solve is at or below the tier's
 * second-limit. Thresholds descend from 5h (tier I) down to 30min (tier V).
 */
readonly final class Speed500PiecesCondition implements BadgeConditionInterface
{
    /**
     * @var array{1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private const array SECONDS_THRESHOLDS = [
        1 => 18_000,
        2 => 7_200,
        3 => 3_600,
        4 => 2_700,
        5 => 1_800,
    ];

    public function badgeType(): BadgeType
    {
        return BadgeType::Speed500Pieces;
    }

    public function qualifiedTiers(PlayerStatsSnapshot $snapshot): array
    {
        $best = $snapshot->best500PieceSoloSeconds;

        if ($best === null) {
            return [];
        }

        $qualified = [];

        foreach (self::SECONDS_THRESHOLDS as $tierValue => $maxSeconds) {
            if ($best <= $maxSeconds) {
                $qualified[] = BadgeTier::from($tierValue);
            }
        }

        return $qualified;
    }

    public function progressToNextTier(PlayerStatsSnapshot $snapshot, null|BadgeTier $highestEarned): null|BadgeProgress
    {
        $nextTierValue = $highestEarned === null ? 1 : $highestEarned->value + 1;

        if ($nextTierValue > 5) {
            return null;
        }

        $best = $snapshot->best500PieceSoloSeconds;

        if ($best === null) {
            return null;
        }

        $nextTier = BadgeTier::from($nextTierValue);
        $target = self::SECONDS_THRESHOLDS[$nextTierValue];

        // How close the player is to the next tier's time limit. Bar fills as best time shrinks toward target.
        $percent = $best > 0 ? (int) floor(min($target / $best, 1.0) * 100) : 100;

        return new BadgeProgress(
            nextTier: $nextTier,
            currentValue: $best,
            targetValue: $target,
            percent: $percent,
        );
    }

    public function requirementForTier(BadgeTier $tier): int
    {
        return self::SECONDS_THRESHOLDS[$tier->value];
    }
}
