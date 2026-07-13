<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\FirstTryCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class FirstTryConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::FirstTry, (new FirstTryCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new FirstTryCondition())->qualifiedTiers($this->snapshot(4)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new FirstTryCondition())->qualifiedTiers($this->snapshot(5)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new FirstTryCondition())->qualifiedTiers($this->snapshot(250)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove1000(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new FirstTryCondition())->qualifiedTiers($this->snapshot(1000)),
        );
    }

    public function testProgressTowardBronzeWithNoBadges(): void
    {
        $progress = (new FirstTryCondition())->progressToNextTier($this->snapshot(3), null);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Bronze, $progress->nextTier);
        self::assertSame(3, $progress->currentValue);
        self::assertSame(5, $progress->targetValue);
        self::assertSame(60, $progress->percent);
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new FirstTryCondition())->progressToNextTier($this->snapshot(45), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(90, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new FirstTryCondition())->progressToNextTier($this->snapshot(1500), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new FirstTryCondition();

        self::assertSame(5, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(50, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(1000, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $firstTrySolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            firstTrySolves: $firstTrySolves,
        );
    }
}
