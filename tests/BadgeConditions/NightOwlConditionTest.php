<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\NightOwlCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class NightOwlConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::NightOwl, (new NightOwlCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new NightOwlCondition())->qualifiedTiers($this->snapshot(9)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new NightOwlCondition())->qualifiedTiers($this->snapshot(10)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new NightOwlCondition())->qualifiedTiers($this->snapshot(75)),
        );
    }

    public function testQualifiesForAllTiersAtDiamond(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new NightOwlCondition())->qualifiedTiers($this->snapshot(250)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new NightOwlCondition())->progressToNextTier($this->snapshot(29), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(29, $progress->currentValue);
        self::assertSame(30, $progress->targetValue);
    }

    public function testRequirementForTier(): void
    {
        $condition = new NightOwlCondition();

        self::assertSame(10, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(30, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(75, $condition->requirementForTier(BadgeTier::Gold));
        self::assertSame(150, $condition->requirementForTier(BadgeTier::Platinum));
        self::assertSame(250, $condition->requirementForTier(BadgeTier::Diamond));
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
            nightOwlSolves: $solves,
        );
    }
}
