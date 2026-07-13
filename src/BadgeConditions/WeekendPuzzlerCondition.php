<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class WeekendPuzzlerCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::WeekendPuzzler;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->weekendSolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 10,
            2 => 50,
            3 => 150,
            4 => 300,
            5 => 600,
        ];
    }
}
