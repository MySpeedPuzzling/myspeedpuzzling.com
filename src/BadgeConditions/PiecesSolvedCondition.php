<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class PiecesSolvedCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::PiecesSolved;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->totalPiecesSolved;
    }

    protected function thresholds(): array
    {
        return [
            1 => 10_000,
            2 => 100_000,
            3 => 500_000,
            4 => 1_000_000,
            5 => 2_000_000,
        ];
    }
}
