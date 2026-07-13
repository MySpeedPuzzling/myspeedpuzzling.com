<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class UnboxedCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::Unboxed;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->unboxedSolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 1,
            2 => 5,
            3 => 25,
            4 => 50,
            5 => 100,
        ];
    }
}
