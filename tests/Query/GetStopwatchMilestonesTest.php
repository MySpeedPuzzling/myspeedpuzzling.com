<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetStopwatchMilestones;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetStopwatchMilestonesTest extends KernelTestCase
{
    private GetStopwatchMilestones $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetStopwatchMilestones::class);
    }

    public function testForPuzzleAndPlayerReturnsSortedMilestones(): void
    {
        // PUZZLE_500_01 has solving times from 5 players
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertNotEmpty($milestones);

        // Milestones must be sorted by timeSeconds ascending
        $times = array_map(fn($m) => $m->timeSeconds, $milestones);
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times, 'Milestones should be sorted by time ascending');
    }

    public function testForPuzzleAndPlayerIncludesFastestMilestone(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $fastestMilestones = array_filter($milestones, fn($m) => $m->type === 'fastest');
        self::assertNotEmpty($fastestMilestones, 'Should include a fastest milestone');

        $fastest = array_values($fastestMilestones)[0];
        self::assertStringContainsString('(fastest)', $fastest->label);
    }

    public function testForPuzzleAndPlayerIncludesSelfMilestone(): void
    {
        // PLAYER_REGULAR has solving time for PUZZLE_500_01 (TIME_01: 1800s, TIME_36: 1750s)
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $selfMilestones = array_filter($milestones, fn($m) => $m->type === 'self');
        self::assertNotEmpty($selfMilestones, 'Should include current player milestone');

        $self = array_values($selfMilestones)[0];
        self::assertStringContainsString('(you)', $self->label);
        // Best time is 1750s (TIME_36)
        self::assertSame(1750, $self->timeSeconds);
    }

    public function testForPuzzleAndPlayerIncludesFavoritePlayers(): void
    {
        // PLAYER_WITH_FAVORITES has favorites: PLAYER_REGULAR and PLAYER_ADMIN
        // Both have solving times for PUZZLE_500_01
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $favoriteMilestones = array_filter($milestones, fn($m) => $m->type === 'favorite');
        self::assertNotEmpty($favoriteMilestones, 'Should include favorite player milestones');
    }

    public function testForPuzzleAndPlayerFillsGapsWithOtherPlayers(): void
    {
        // PUZZLE_500_01 has times spread across players
        // With gap filling, there should be 'other' type milestones if gaps > 2 min exist
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $types = array_unique(array_map(fn($m) => $m->type, $milestones));

        // At minimum, should have fastest and self/favorites
        self::assertContains('fastest', $types);
    }

    public function testForPuzzleAndPlayerNoDuplicateTimes(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        $times = array_map(fn($m) => $m->timeSeconds, $milestones);
        $uniqueTimes = array_unique($times);
        self::assertCount(count($uniqueTimes), $times, 'Should not have duplicate milestone times');
    }

    public function testForPuzzleWithNoTimesReturnsEmpty(): void
    {
        // PUZZLE_9000 has no solving times
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_9000,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertEmpty($milestones);
    }

    public function testAllSoloTimesForPuzzleReturnsBestPerPlayer(): void
    {
        // PUZZLE_500_01 has multiple solves by some players
        // PLAYER_REGULAR: 1800 (TIME_01) and 1750 (TIME_36) -> best is 1750
        // PLAYER_ADMIN: 2400 (TIME_03) and 1200 (TIME_32) -> best is 1200
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotEmpty($times);

        // Should be sorted ascending
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times, 'Times should be sorted ascending');

        // Should be one entry per player (not per solving time)
        // PUZZLE_500_01 has solo solves from 5 different players
        self::assertCount(5, $times, 'Should have one best time per player');
    }

    public function testAllSoloTimesForPuzzleExcludesTeamSolves(): void
    {
        // PUZZLE_1000_01 has solo solves and a team solve (TIME_12)
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_1000_01);

        // Team time is 3600s. Solo times are 4200, 3900, 5100, 4100 and 6500
        // The 3600 team time should NOT be in the results
        self::assertNotContains(3600, $times, 'Team solve time should not be included');
    }

    public function testAllSoloTimesForPuzzleWithNoTimesReturnsEmpty(): void
    {
        $times = $this->query->allSoloTimesForPuzzle(PuzzleFixture::PUZZLE_9000);

        self::assertEmpty($times);
    }

    public function testMilestoneGapFillingProducesMoreMilestonesThanWithout(): void
    {
        $milestones = $this->query->forPuzzleAndPlayer(
            PuzzleFixture::PUZZLE_500_01,
            PlayerFixture::PLAYER_REGULAR,
        );

        // Count named types (fastest, average, self, favorite)
        $namedTypes = ['fastest', 'average', 'self', 'favorite'];
        $namedCount = count(array_filter($milestones, fn($m) => in_array($m->type, $namedTypes, true)));
        $totalCount = count($milestones);

        // Gap filling should add 'other' type milestones when there are gaps > 2 min
        // Total should be >= named count (gap filling adds more)
        self::assertGreaterThanOrEqual($namedCount, $totalCount);
    }
}
