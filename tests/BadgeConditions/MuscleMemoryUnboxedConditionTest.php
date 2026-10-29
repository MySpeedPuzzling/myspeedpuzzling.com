<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\MuscleMemoryUnboxedCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class MuscleMemoryUnboxedConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::MuscleMemoryUnboxed, (new MuscleMemoryUnboxedCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new MuscleMemoryUnboxedCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new MuscleMemoryUnboxedCondition())->qualifiedTiers($this->snapshot(2)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new MuscleMemoryUnboxedCondition())->qualifiedTiers($this->snapshot(8)),
        );
    }

    public function testQualifiesForAllTiersAtDiamond(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new MuscleMemoryUnboxedCondition())->qualifiedTiers($this->snapshot(25)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new MuscleMemoryUnboxedCondition())->progressToNextTier($this->snapshot(3), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(3, $progress->currentValue);
        self::assertSame(4, $progress->targetValue);
    }

    public function testRequirementForTier(): void
    {
        $condition = new MuscleMemoryUnboxedCondition();

        self::assertSame(2, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(4, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(8, $condition->requirementForTier(BadgeTier::Gold));
        self::assertSame(15, $condition->requirementForTier(BadgeTier::Platinum));
        self::assertSame(25, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $solves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            muscleMemoryUnboxedSolves: $solves,
        );
    }
}
