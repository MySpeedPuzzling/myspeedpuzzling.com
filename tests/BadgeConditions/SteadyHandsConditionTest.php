<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\SteadyHandsCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class SteadyHandsConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::SteadyHands, (new SteadyHandsCondition())->badgeType());
    }

    public function testSingleQuarterIsBelowFirstThreshold(): void
    {
        self::assertSame([], (new SteadyHandsCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new SteadyHandsCondition())->qualifiedTiers($this->snapshot(2)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new SteadyHandsCondition())->qualifiedTiers($this->snapshot(9)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove16(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new SteadyHandsCondition())->qualifiedTiers($this->snapshot(16)),
        );
    }

    public function testProgressTowardBronzeWithNoBadges(): void
    {
        $progress = (new SteadyHandsCondition())->progressToNextTier($this->snapshot(1), null);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Bronze, $progress->nextTier);
        self::assertSame(1, $progress->currentValue);
        self::assertSame(2, $progress->targetValue);
        self::assertSame(50, $progress->percent);
    }

    public function testProgressTowardGoldWithSilverEarned(): void
    {
        $progress = (new SteadyHandsCondition())->progressToNextTier($this->snapshot(6), BadgeTier::Silver);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Gold, $progress->nextTier);
        self::assertSame(75, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new SteadyHandsCondition())->progressToNextTier($this->snapshot(20), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new SteadyHandsCondition();

        self::assertSame(2, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(4, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(16, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $steadyHandsQuarters): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            steadyHandsQuarters: $steadyHandsQuarters,
        );
    }
}
