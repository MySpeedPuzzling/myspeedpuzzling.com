<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\MarathonerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class MarathonerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Marathoner, (new MarathonerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new MarathonerCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new MarathonerCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new MarathonerCondition())->qualifiedTiers($this->snapshot(20)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove100(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new MarathonerCondition())->qualifiedTiers($this->snapshot(100)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new MarathonerCondition())->progressToNextTier($this->snapshot(4), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(4, $progress->currentValue);
        self::assertSame(5, $progress->targetValue);
        self::assertSame(80, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new MarathonerCondition())->progressToNextTier($this->snapshot(150), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new MarathonerCondition();

        self::assertSame(1, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(5, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(100, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $marathonerSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            marathonerSolves: $marathonerSolves,
        );
    }
}
