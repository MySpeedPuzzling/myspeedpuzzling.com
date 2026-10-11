<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class MarathonerCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::Marathoner;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->marathonerSolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 5,
            2 => 10,
            3 => 20,
            4 => 40,
            5 => 100,
        ];
    }
}
