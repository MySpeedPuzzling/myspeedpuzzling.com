<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\WeekendPuzzlerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class WeekendPuzzlerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::WeekendPuzzler, (new WeekendPuzzlerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new WeekendPuzzlerCondition())->qualifiedTiers($this->snapshot(9)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new WeekendPuzzlerCondition())->qualifiedTiers($this->snapshot(10)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new WeekendPuzzlerCondition())->qualifiedTiers($this->snapshot(200)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove600(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new WeekendPuzzlerCondition())->qualifiedTiers($this->snapshot(600)),
        );
    }

    public function testProgressTowardBronzeWithNoBadges(): void
    {
        $progress = (new WeekendPuzzlerCondition())->progressToNextTier($this->snapshot(5), null);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Bronze, $progress->nextTier);
        self::assertSame(5, $progress->currentValue);
        self::assertSame(10, $progress->targetValue);
        self::assertSame(50, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new WeekendPuzzlerCondition())->progressToNextTier($this->snapshot(700), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new WeekendPuzzlerCondition();

        self::assertSame(10, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(50, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(600, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $weekendSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            weekendSolves: $weekendSolves,
        );
    }
}
