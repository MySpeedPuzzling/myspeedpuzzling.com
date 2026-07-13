<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class BrandExplorerCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::BrandExplorer;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->brandExplorerManufacturers;
    }

    protected function thresholds(): array
    {
        return [
            1 => 3,
            2 => 10,
            3 => 25,
            4 => 50,
            5 => 100,
        ];
    }
}
