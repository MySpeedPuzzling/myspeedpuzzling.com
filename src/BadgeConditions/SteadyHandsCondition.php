<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class SteadyHandsCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::SteadyHands;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->steadyHandsQuarters;
    }

    protected function thresholds(): array
    {
        return [
            1 => 2,
            2 => 4,
            3 => 8,
            4 => 12,
            5 => 16,
        ];
    }
}
