<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\BrandExplorerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class BrandExplorerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::BrandExplorer, (new BrandExplorerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new BrandExplorerCondition())->qualifiedTiers($this->snapshot(2)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new BrandExplorerCondition())->qualifiedTiers($this->snapshot(3)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new BrandExplorerCondition())->qualifiedTiers($this->snapshot(30)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove100(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new BrandExplorerCondition())->qualifiedTiers($this->snapshot(100)),
        );
    }

    public function testProgressTowardBronzeWithNoBadges(): void
    {
        $progress = (new BrandExplorerCondition())->progressToNextTier($this->snapshot(2), null);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Bronze, $progress->nextTier);
        self::assertSame(2, $progress->currentValue);
        self::assertSame(3, $progress->targetValue);
        self::assertSame(66, $progress->percent);
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new BrandExplorerCondition())->progressToNextTier($this->snapshot(9), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(90, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new BrandExplorerCondition())->progressToNextTier($this->snapshot(120), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new BrandExplorerCondition();

        self::assertSame(3, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(10, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(100, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $brandExplorerManufacturers): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            brandExplorerManufacturers: $brandExplorerManufacturers,
        );
    }
}
