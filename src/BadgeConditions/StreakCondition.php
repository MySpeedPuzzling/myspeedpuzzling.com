<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class StreakCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::Streak;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->allTimeLongestStreakDays;
    }

    protected function thresholds(): array
    {
        return [
            1 => 7,
            2 => 30,
            3 => 90,
            4 => 180,
            5 => 365,
        ];
    }
}
