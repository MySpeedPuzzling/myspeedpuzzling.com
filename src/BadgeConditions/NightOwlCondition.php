<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class NightOwlCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::NightOwl;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->nightOwlSolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 10,
            2 => 30,
            3 => 75,
            4 => 150,
            5 => 250,
        ];
    }
}
