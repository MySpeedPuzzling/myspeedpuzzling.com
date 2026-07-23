<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\BadgeConditions;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\BadgeConditions\TeamPlayerCondition;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

final class TeamPlayerConditionTest extends TestCase
{
    public function testBadgeType(): void
    {
        self::assertSame(BadgeType::TeamPlayer, (new TeamPlayerCondition())->badgeType());
    }

    public function testFirstTeamSolveEarnsBronze(): void
    {
        self::assertSame([BadgeTier::Bronze], (new TeamPlayerCondition())->qualifiedTiers($this->snapshot(1)));
    }

    public function testFourSolvesStillOnlyBronze(): void
    {
        self::assertSame([BadgeTier::Bronze], (new TeamPlayerCondition())->qualifiedTiers($this->snapshot(4)));
    }

    public function testFiveSolvesEarnBronzeAndSilver(): void
    {
        self::assertSame(
            [BadgeTier::Bronze, BadgeTier::Silver],
            (new TeamPlayerCondition())->qualifiedTiers($this->snapshot(5)),
        );
    }

    public function testFiveHundredSolvesEarnAllTiers(): void
    {
        self::assertCount(5, (new TeamPlayerCondition())->qualifiedTiers($this->snapshot(500)));
    }

    public function testProgressTowardSilverFromBronze(): void
    {
        $progress = (new TeamPlayerCondition())->progressToNextTier($this->snapshot(3), BadgeTier::Bronze);

        self::assertNotNull($progress);
        self::assertSame(BadgeTier::Silver, $progress->nextTier);
        self::assertSame(3, $progress->currentValue);
        self::assertSame(5, $progress->targetValue);
        self::assertSame(60, $progress->percent);
    }

    private function snapshot(int $teamSolves): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: '018d0000-0000-0000-0000-000000000000',
            distinctPuzzlesSolved: 0,
            totalPiecesSolved: 0,
            best500PieceSoloSeconds: null,
            allTimeLongestStreakDays: 0,
            teamSolvesCount: $teamSolves,
        );
    }
}
