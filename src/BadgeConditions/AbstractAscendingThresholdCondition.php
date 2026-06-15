<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;

/**
 * Base class for count-based tiered badges where higher values earn higher tiers.
 *
 * Subclasses declare ordered thresholds (Bronze → Diamond) and extract the raw
 * metric from a snapshot. Tier qualification is `metric >= threshold`.
 */
abstract readonly class AbstractAscendingThresholdCondition implements BadgeConditionInterface
{
    abstract protected function currentValue(PlayerStatsSnapshot $snapshot): int;

    /**
     * @return array{1: int, 2: int, 3: int, 4: int, 5: int}
     */
    abstract protected function thresholds(): array;

    public function qualifiedTiers(PlayerStatsSnapshot $snapshot): array
    {
        $current = $this->currentValue($snapshot);
        $qualified = [];

        foreach ($this->thresholds() as $tierValue => $threshold) {
            if ($current >= $threshold) {
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

        $nextTier = BadgeTier::from($nextTierValue);
        $target = $this->thresholds()[$nextTierValue];
        $current = $this->currentValue($snapshot);
        $percent = $target > 0 ? (int) floor(min($current, $target) / $target * 100) : 0;

        return new BadgeProgress(
            nextTier: $nextTier,
            currentValue: $current,
            targetValue: $target,
            percent: $percent,
        );
    }

    public function requirementForTier(BadgeTier $tier): int
    {
        return $this->thresholds()[$tier->value];
    }
}
