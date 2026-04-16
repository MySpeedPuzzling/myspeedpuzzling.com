<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\BadgeConditions;

use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class PuzzlesSolvedCondition extends AbstractAscendingThresholdCondition
{
    public function badgeType(): BadgeType
    {
        return BadgeType::PuzzlesSolved;
    }

    protected function currentValue(PlayerStatsSnapshot $snapshot): int
    {
        return $snapshot->distinctPuzzlesSolved;
    }

    protected function thresholds(): array
    {
        return [
            1 => 10,
            2 => 100,
            3 => 500,
            4 => 1000,
            5 => 2000,
        ];
    }
}
