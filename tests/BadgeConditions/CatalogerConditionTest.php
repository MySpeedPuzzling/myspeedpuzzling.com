<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\CatalogerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class CatalogerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Cataloger, (new CatalogerCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        self::assertSame([], (new CatalogerCondition())->qualifiedTiers($this->snapshot(0)));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new CatalogerCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new CatalogerCondition())->qualifiedTiers($this->snapshot(60)),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove300(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new CatalogerCondition())->qualifiedTiers($this->snapshot(300)),
        );
    }

    public function testProgressTowardSilverWithBronzeEarned(): void
    {
        $progress = (new CatalogerCondition())->progressToNextTier($this->snapshot(8), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(8, $progress->currentValue);
        self::assertSame(10, $progress->targetValue);
        self::assertSame(80, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        self::assertNull((new CatalogerCondition())->progressToNextTier($this->snapshot(400), BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new CatalogerCondition();

        self::assertSame(1, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(10, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(300, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $catalogerApprovedPuzzles): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            catalogerApprovedPuzzles: $catalogerApprovedPuzzles,
        );
    }
}
