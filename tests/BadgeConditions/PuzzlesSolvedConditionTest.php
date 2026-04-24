<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\PuzzlesSolvedCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class PuzzlesSolvedConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::PuzzlesSolved, (new PuzzlesSolvedCondition())->badgeType());
    }

    public function testNoQualifyingTiersWhenBelowFirstThreshold(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 9);

        self::assertSame([], (new PuzzlesSolvedCondition())->qualifiedTiers($snapshot));
    }

    public function testQualifiesForFirstTierAtExactThreshold(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 10);

        self::assertSame(
            [BadgeTier::Bronze],
            (new PuzzlesSolvedCondition())->qualifiedTiers($snapshot),
        );
    }

    public function testQualifiesForAllLowerTiersWhenSkippingAhead(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 600);

        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new PuzzlesSolvedCondition())->qualifiedTiers($snapshot),
        );
    }

    public function testQualifiesForAllTiersAtOrAbove2000(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 2500);

        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold, BadgeTier::Platinum, BadgeTier::Diamond],
            (new PuzzlesSolvedCondition())->qualifiedTiers($snapshot),
        );
    }

    public function testProgressTowardBronzeWithNoBadges(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 3);

        $progress = (new PuzzlesSolvedCondition())->progressToNextTier($snapshot, null);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Bronze, $progress->nextTier);
        self::assertSame(3, $progress->currentValue);
        self::assertSame(10, $progress->targetValue);
        self::assertSame(30, $progress->percent);
    }

    public function testProgressCapsAt100Percent(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 90);

        $progress = (new PuzzlesSolvedCondition())->progressToNextTier($snapshot, BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(90, $progress->percent);
    }

    public function testProgressIsNullWhenAllTiersEarned(): void
    {
        $snapshot = $this->snapshot(distinctPuzzles: 3000);

        self::assertNull((new PuzzlesSolvedCondition())->progressToNextTier($snapshot, BadgeTier::Diamond));
    }

    public function testRequirementForTier(): void
    {
        $condition = new PuzzlesSolvedCondition();

        self::assertSame(10, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(100, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(2000, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(int $distinctPuzzles): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: $distinctPuzzles,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
        );
    }
}
