<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\MuscleMemoryCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class MuscleMemoryConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::MuscleMemory, (new MuscleMemoryCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new MuscleMemoryCondition())->qualifiedTiers($this->snapshot(2)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new MuscleMemoryCondition())->qualifiedTiers($this->snapshot(3)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new MuscleMemoryCondition())->qualifiedTiers($this->snapshot(15)),
        );
    }

    public function testQualifiesForAllTiersAtDiamond(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new MuscleMemoryCondition())->qualifiedTiers($this->snapshot(50)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new MuscleMemoryCondition())->progressToNextTier($this->snapshot(7), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(7, $progress->currentValue);
        self::assertSame(8, $progress->targetValue);
    }

    public function testRequirementForTier(): void
    {
        $condition = new MuscleMemoryCondition();

        self::assertSame(3, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(8, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(15, $condition->requirementForTier(BadgeTier::Gold));
        self::assertSame(30, $condition->requirementForTier(BadgeTier::Platinum));
        self::assertSame(50, $condition->requirementForTier(BadgeTier::Diamond));
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
            muscleMemorySolves: $solves,
        );
    }
}
