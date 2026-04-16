<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\PiecesSolvedCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class PiecesSolvedConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::PiecesSolved, (new PiecesSolvedCondition())->badgeType());
    }

    public function testNoTiersBelowTenThousand(): void
    {
        self::assertSame([], (new PiecesSolvedCondition())->qualifiedTiers($this->snapshot(9_999)));
    }

    public function testQualifiesAtThreshold(): void
    {
        self::assertSame([BadgeTier::Bronze], (new PiecesSolvedCondition())->qualifiedTiers($this->snapshot(10_000)));
    }

    public function testAllTiersAtTwoMillion(): void
    {
        self::assertCount(5, (new PiecesSolvedCondition())->qualifiedTiers($this->snapshot(2_000_000)));
    }

    public function testProgressFromGoldTowardPlatinum(): void
    {
        $progress = (new PiecesSolvedCondition())->progressToNextTier($this->snapshot(750_000), BadgeTier::Gold);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Platinum, $progress->nextTier);
        self::assertSame(750_000, $progress->currentValue);
        self::assertSame(1_000_000, $progress->targetValue);
        self::assertSame(75, $progress->percent);
    }

    private function snapshot(int $pieces): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: $pieces,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
        );
    }
}
