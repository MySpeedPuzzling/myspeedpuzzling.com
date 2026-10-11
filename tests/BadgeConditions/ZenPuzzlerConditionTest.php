<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\ZenPuzzlerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class ZenPuzzlerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::ZenPuzzler, (new ZenPuzzlerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new ZenPuzzlerCondition())->qualifiedTiers($this->snapshot(4)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new ZenPuzzlerCondition())->qualifiedTiers($this->snapshot(5)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new ZenPuzzlerCondition())->qualifiedTiers($this->snapshot(60)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove365(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new ZenPuzzlerCondition())->qualifiedTiers($this->snapshot(365)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new ZenPuzzlerCondition())->progressToNextTier($this->snapshot(9), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(9, $progress->currentValue);
        self::assertSame(15, $progress->targetValue);
        self::assertSame(60, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new ZenPuzzlerCondition())->progressToNextTier($this->snapshot(400), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new ZenPuzzlerCondition();

        self::assertSame(5, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(15, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(365, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $zenPuzzlerSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            zenPuzzlerSolves: $zenPuzzlerSolves,
        );
    }
}
