<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\CompetitorCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class CompetitorConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Competitor, (new CompetitorCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new CompetitorCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new CompetitorCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new CompetitorCondition())->qualifiedTiers($this->snapshot(6)),
        );
    }

    public function testQualifiesForAllTiersAtDiamond(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new CompetitorCondition())->qualifiedTiers($this->snapshot(25)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new CompetitorCondition())->progressToNextTier($this->snapshot(2), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(2, $progress->currentValue);
        self::assertSame(3, $progress->targetValue);
    }

    public function testRequirementForTier(): void
    {
        $condition = new CompetitorCondition();

        self::assertSame(1, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(3, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(6, $condition->requirementForTier(BadgeTier::Gold));
        self::assertSame(12, $condition->requirementForTier(BadgeTier::Platinum));
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
            competitorEvents: $solves,
        );
    }
}
