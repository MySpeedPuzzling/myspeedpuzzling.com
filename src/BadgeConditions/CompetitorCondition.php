<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class CompetitorCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::Competitor;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->competitorEvents;
    }

    protected function thresholds(): array
    {
        return [
            1 => 1,
            2 => 3,
            3 => 6,
            4 => 12,
            5 => 25,
        ];
    }
}
