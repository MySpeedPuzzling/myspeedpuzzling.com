<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\Speed500PiecesCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class Speed500PiecesConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::Speed500Pieces, (new Speed500PiecesCondition())->badgeType());
    }

    public function testNoSolveMeansNoTiers(): void
    {
        self::assertSame([], (new Speed500PiecesCondition())->qualifiedTiers($this->snapshot(null)));
    }

    public function testSlowerThan5HoursYieldsNothing(): void
    {
        self::assertSame([], (new Speed500PiecesCondition())->qualifiedTiers($this->snapshot(18_001)));
    }

    public function testExactlyThreshold5HoursEarnsBronze(): void
    {
        self::assertSame([BadgeTier::Bronze], (new Speed500PiecesCondition())->qualifiedTiers($this->snapshot(18_000)));
    }

    public function testSub30MinEarnsAllTiers(): void
    {
        self::assertCount(5, (new Speed500PiecesCondition())->qualifiedTiers($this->snapshot(1_500)));
    }

    public function testProgressTowardDiamondShowsShrinkingRatio(): void
    {
        // Best time 2500s (~42min), target for Diamond is 1800s (30min)
        $progress = (new Speed500PiecesCondition())->progressToNextTier($this->snapshot(2_500), BadgeTier::Platinum);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Diamond, $progress->nextTier);
        self::assertSame(2_500, $progress->currentValue);
        self::assertSame(1_800, $progress->targetValue);
        self::assertSame(72, $progress->percent);
    }

    public function testProgressIsNullWithoutAnySolve(): void
    {
        self::assertNull((new Speed500PiecesCondition())->progressToNextTier($this->snapshot(null), null));
    }

    public function testProgressIsNullWhenDiamondEarned(): void
    {
        self::assertNull(
            (new Speed500PiecesCondition())->progressToNextTier($this->snapshot(1_500), BadgeTier::Diamond),
        );
    }

    private function snapshot(null|int $best500SoloSeconds): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: $best500SoloSeconds,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
        );
    }
}
