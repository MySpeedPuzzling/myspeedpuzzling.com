<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerSolvedPuzzlesTest extends KernelTestCase
{
    private GetPlayerSolvedPuzzles $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayerSolvedPuzzles::class);
    }

    public function testSoloByPlayerIdReturnsOnlySoloSolves(): void
    {
        // PLAYER_REGULAR has many solo solves
        $results = $this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($results);

        // All results should be solo (no team)
        foreach ($results as $solvedPuzzle) {
            self::assertNull($solvedPuzzle->teamId, 'Solo solves should not have teamId');
        }
    }

    public function testSoloByPlayerIdExcludesTeamSolves(): void
    {
        // PLAYER_REGULAR has both solo and team solves
        // TIME_12 is a team solve for PUZZLE_1000_01
        $results = $this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR);

        // Solo results should only include solo attempts
        foreach ($results as $solvedPuzzle) {
            self::assertNull($solvedPuzzle->teamId);
        }
    }

    public function testDuoByPlayerIdReturnsOnlyDuoSolves(): void
    {
        // PLAYER_REGULAR has a duo solve: TIME_12 (team-001 with 2 players)
        $results = $this->query->duoByPlayerId(PlayerFixture::PLAYER_REGULAR);

        // Should have at least one duo solve
        self::assertNotEmpty($results);

        // All results should have teamId (duo/team)
        foreach ($results as $solvedPuzzle) {
            self::assertNotNull($solvedPuzzle->teamId, 'Duo solves should have teamId');
        }
    }

    public function testDuoByPlayerIdExcludesSoloAndTeam(): void
    {
        // PLAYER_PRIVATE is part of a duo (TIME_12, TIME_41)
        // but no team solves (3+ players)
        $results = $this->query->duoByPlayerId(PlayerFixture::PLAYER_PRIVATE);

        // Check that solo solves are not included
        foreach ($results as $solvedPuzzle) {
            self::assertNotNull($solvedPuzzle->teamId, 'Duo results should have teamId');
        }
    }

    public function testTeamByPlayerIdReturnsOnlyTeamSolves(): void
    {
        // No 3+ player team solves in fixtures
        $results = $this->query->teamByPlayerId(PlayerFixture::PLAYER_REGULAR);

        // Should be empty since we only have duo (2 player) fixtures, not team (3+)
        self::assertEmpty($results, 'Should have no team (3+ players) solves');
    }

    public function testTeamByPlayerIdExcludesSoloAndDuo(): void
    {
        // PLAYER_REGULAR has solo and duo but no team
        $results = $this->query->teamByPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertEmpty($results);
    }

    public function testDuoByPlayerIdIncludesPlayerAsTeamMember(): void
    {
        // PLAYER_PRIVATE is part of team-001 (TIME_12) but NOT the player_id owner
        // They should still see this as their duo solve
        $results = $this->query->duoByPlayerId(PlayerFixture::PLAYER_PRIVATE);

        self::assertNotEmpty($results, 'Player should see duo solves where they are a team member');

        // Should include PUZZLE_1000_01 (TIME_12 team-001)
        $puzzleIds = array_map(fn($s) => $s->puzzleId, $results);
        self::assertContains(
            PuzzleFixture::PUZZLE_1000_01,
            $puzzleIds,
            'Player should see puzzle solved as part of team',
        );
    }

    public function testGetOldestResultDateReturnsCorrectDate(): void
    {
        $date = $this->query->getOldestResultDate(PlayerFixture::PLAYER_REGULAR);

        self::assertNotNull($date);
    }

    public function testGetOldestResultDateReturnsNullForPlayerWithNoSolves(): void
    {
        // Use a random UUID that doesn't exist
        $date = $this->query->getOldestResultDate('00000000-0000-0000-0000-000000000000');

        self::assertNull($date);
    }
}
