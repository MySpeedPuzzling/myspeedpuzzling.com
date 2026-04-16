<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\StreakCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class StreakConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Streak, (new StreakCondition())->badgeType());
    }

    public function testZeroStreakHasNoTier(): void
    {
        self::assertSame([], (new StreakCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testSixDaysShortOfFirstTier(): void
    {
        self::assertSame([], (new StreakCondition())->qualifiedTiers($this->snapshot(6)));
    }

    public function testSevenDaysEarnsBronze(): void
    {
        self::assertSame([BadgeTier::Bronze], (new StreakCondition())->qualifiedTiers($this->snapshot(7)));
    }

    public function testFullYearEarnsAllTiers(): void
    {
        self::assertCount(5, (new StreakCondition())->qualifiedTiers($this->snapshot(365)));
    }

    public function testProgressTowardGold(): void
    {
        $progress = (new StreakCondition())->progressToNextTier($this->snapshot(60), BadgeTier::Silver);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Gold, $progress->nextTier);
        self::assertSame(60, $progress->currentValue);
        self::assertSame(90, $progress->targetValue);
        self::assertSame(66, $progress->percent);
    }

    private function snapshot(int $streakDays): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: $streakDays,
            teamSolvesCount: 0,
        );
    }
}
