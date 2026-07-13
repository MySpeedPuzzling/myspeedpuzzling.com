<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class PhotographerCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::Photographer;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->photographerSolves;
    }

    protected function thresholds(): array
    {
        return [
            1 => 1,
            2 => 25,
            3 => 100,
            4 => 500,
            5 => 1000,
        ];
    }
}
