<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\UnboxedCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class UnboxedConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Unboxed, (new UnboxedCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new UnboxedCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new UnboxedCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new UnboxedCondition())->qualifiedTiers($this->snapshot(30)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove100(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new UnboxedCondition())->qualifiedTiers($this->snapshot(100)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new UnboxedCondition())->progressToNextTier($this->snapshot(3), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(3, $progress->currentValue);
        self::assertSame(5, $progress->targetValue);
        self::assertSame(60, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new UnboxedCondition())->progressToNextTier($this->snapshot(150), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new UnboxedCondition();

        self::assertSame(1, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(5, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(100, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $unboxedSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            unboxedSolves: $unboxedSolves,
        );
    }
}
