<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class MuscleMemoryCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::MuscleMemory;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->muscleMemorySolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 3,
            2 => 8,
            3 => 15,
            4 => 30,
            5 => 50,
        ];
    }
}
