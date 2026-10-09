<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\SpeedDemon1000Condition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class SpeedDemon1000ConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::SpeedDemon1000, (new SpeedDemon1000Condition())->badgeType());
    }

    public function testNoSolveMeansNoTiers(): void
    {
        self::assertSame([], (new SpeedDemon1000Condition())->qualifiedTiers($this->snapshot(null)));
    }

    public function testSlowerThan8HoursYieldsNothing(): void
    {
        self::assertSame([], (new SpeedDemon1000Condition())->qualifiedTiers($this->snapshot(28_801)));
    }

    public function testExactlyThreshold8HoursEarnsBronze(): void
    {
        self::assertSame([BadgeTier::Bronze], (new SpeedDemon1000Condition())->qualifiedTiers($this->snapshot(28_800)));
    }

    public function testFasterTimeQualifiesAllLowerTiers(): void
    {
        // 2 hours: under 8h, 4h and 2.5h limits, but slower than 1h45m.
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver, BadgeTier::Gold],
            (new SpeedDemon1000Condition())->qualifiedTiers($this->snapshot(7_200)),
        );
    }

    public function testSub75MinutesEarnsAllTiers(): void
    {
        self::assertCount(5, (new SpeedDemon1000Condition())->qualifiedTiers($this->snapshot(4_000)));
    }

    public function testProgressTowardDiamondShowsShrinkingRatio(): void
    {
        // Best time 6000s (1h40m), target for Diamond is 4500s (1h15m)
        $progress = (new SpeedDemon1000Condition())->progressToNextTier($this->snapshot(6_000), BadgeTier::Platinum);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Diamond, $progress->nextTier);
        self::assertSame(6_000, $progress->currentValue);
        self::assertSame(4_500, $progress->targetValue);
        self::assertSame(75, $progress->percent);
    }

    public function testProgressIsNullWithoutAnySolve(): void
    {
        self::assertNull((new SpeedDemon1000Condition())->progressToNextTier($this->snapshot(null), null));
    }

    public function testProgressIsNullWhenDiamondEarned(): void
    {
        self::assertNull(
            (new SpeedDemon1000Condition())->progressToNextTier($this->snapshot(4_000), BadgeTier::Diamond),
        );
    }

    public function testRequirementForTier(): void
    {
        $condition = new SpeedDemon1000Condition();

        self::assertSame(28_800, $condition->requirementForTier(BadgeTier::Bronze));
        self::assertSame(14_400, $condition->requirementForTier(BadgeTier::Silver));
        self::assertSame(9_000, $condition->requirementForTier(BadgeTier::Gold));
        self::assertSame(6_300, $condition->requirementForTier(BadgeTier::Platinum));
        self::assertSame(4_500, $condition->requirementForTier(BadgeTier::Diamond));
    }

    private function snapshot(null|int $best1000SoloSeconds): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: 0,
            best1000PieceSoloSeconds: $best1000SoloSeconds,
        );
    }
}
