<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\PhotographerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class PhotographerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Photographer, (new PhotographerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new PhotographerCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new PhotographerCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new PhotographerCondition())->qualifiedTiers($this->snapshot(120)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove1000(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new PhotographerCondition())->qualifiedTiers($this->snapshot(1000)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new PhotographerCondition())->progressToNextTier($this->snapshot(20), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(20, $progress->currentValue);
        self::assertSame(25, $progress->targetValue);
        self::assertSame(80, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new PhotographerCondition())->progressToNextTier($this->snapshot(1200), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new PhotographerCondition();

        self::assertSame(1, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(25, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(1000, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $photographerSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            photographerSolves: $photographerSolves,
        );
    }
}
