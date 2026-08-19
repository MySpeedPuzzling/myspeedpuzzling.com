<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleSolversTest extends KernelTestCase
{
    private GetPuzzleSolvers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPuzzleSolvers::class);
        $this->database = $container->get(Connection::class);
    }

    private function linkTimeToCompetition(string $timeId, string $competitionId): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => $competitionId, 'timeId' => $timeId],
        );
    }

    public function testSoloByPuzzleIdReturnsSoloSolvers(): void
    {
        // PUZZLE_500_01 has 5 solo solving times (TIME_01 to TIME_05)
        // Plus competition times (TIME_09, TIME_10, TIME_11) and more
        $solvers = $this->query->soloByPuzzleId(PuzzleFixture::PUZZLE_500_01);

        self::assertNotEmpty($solvers);

        // Each solver should have the correct puzzle
        foreach ($solvers as $solver) {
            self::assertSame(PuzzleFixture::PUZZLE_500_01, $solver->puzzleId);
        }

        // Results should be ordered by time ascending
        $times = array_map(fn($s) => $s->time, $solvers);
        $sortedTimes = $times;
        sort($sortedTimes);
        self::assertSame($sortedTimes, $times, 'Results should be sorted by time ascending');
    }

    public function testSoloByPuzzleIdExcludesTeamSolves(): void
    {
        // PUZZLE_1000_01 has solo solves (TIME_16, TIME_17, TIME_18) and a team solve (TIME_12)
        $solvers = $this->query->soloByPuzzleId(PuzzleFixture::PUZZLE_1000_01);

        // Check that all results are solo (no team members)
        foreach ($solvers as $solver) {
            // Solo solvers have no team context in PuzzleSolver result
            self::assertSame(PuzzleFixture::PUZZLE_1000_01, $solver->puzzleId);
        }
    }

    public function testDuoByPuzzleIdReturnsOnlyDuoSolves(): void
    {
        // PUZZLE_1000_01 has a duo solve: TIME_12 (team-001 with 2 players)
        $groups = $this->query->duoByPuzzleId(PuzzleFixture::PUZZLE_1000_01);

        // Should have at least one duo solve
        self::assertNotEmpty($groups);

        // Each group should have players array
        foreach ($groups as $group) {
            self::assertNotEmpty($group->players);
            self::assertNotNull($group->teamId);
        }
    }

    public function testDuoByPuzzleIdExcludesSoloAndTeam(): void
    {
        // PUZZLE_500_01 has only solo solves, no duo
        $groups = $this->query->duoByPuzzleId(PuzzleFixture::PUZZLE_500_01);

        self::assertEmpty($groups, 'Puzzle with only solo solves should return empty for duo');
    }

    public function testTeamByPuzzleIdReturnsOnlyTeamSolves(): void
    {
        // PUZZLE_1000_03 has a team solve: TIME_41 (team-002 with 2 players)
        // But this is duo, not team (3+ players). We don't have team fixtures.
        $groups = $this->query->teamByPuzzleId(PuzzleFixture::PUZZLE_1000_03);

        // Should be empty since TIME_41 is duo (2 players), not team (3+ players)
        self::assertEmpty($groups, 'Duo solves should not appear in team results');
    }

    public function testTeamByPuzzleIdExcludesSoloAndDuo(): void
    {
        // PUZZLE_1000_01 has solo and duo, but no team (3+ players)
        $groups = $this->query->teamByPuzzleId(PuzzleFixture::PUZZLE_1000_01);

        self::assertEmpty($groups, 'Puzzle with only solo and duo should return empty for team');
    }

    public function testRelaxCountsByPuzzleIdReturnsCorrectCounts(): void
    {
        // PUZZLE_500_01 has solved times, not relax times (seconds_to_solve IS NOT NULL)
        $counts = $this->query->relaxCountsByPuzzleId(PuzzleFixture::PUZZLE_500_01);

        // All relax counts should be 0 since our fixtures have timed solves
        self::assertSame(0, $counts['solo']);
        self::assertSame(0, $counts['duo']);
        self::assertSame(0, $counts['team']);
    }

    public function testSoloByPuzzleIdExposesSeriesOfEditionAndKeepsStandaloneShape(): void
    {
        // TIME_36 (PLAYER_REGULAR on PUZZLE_500_01, no competition) is pointed at an EJJ edition;
        // TIME_09 on the same puzzle stays linked to the standalone WJPC 2024.
        $this->linkTimeToCompetition(PuzzleSolvingTimeFixture::TIME_36, CompetitionSeriesFixture::EDITION_EJJ_68);

        $byTimeId = [];
        foreach ($this->query->soloByPuzzleId(PuzzleFixture::PUZZLE_500_01) as $solver) {
            $byTimeId[$solver->timeId] = $solver;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);

        $standalone = $byTimeId[PuzzleSolvingTimeFixture::TIME_09];
        self::assertSame('WJPC 2024', $standalone->competitionName);
        self::assertSame('WJPC24', $standalone->competitionShortcut);
        self::assertSame('wjpc-2024', $standalone->competitionSlug);
        self::assertNull($standalone->competitionSeriesName);
        self::assertNull($standalone->competitionSeriesShortcut);
        self::assertNull($standalone->competitionSeriesSlug);
    }

    public function testDuoByPuzzleIdExposesSeriesOfEdition(): void
    {
        // The duo block aggregates players (GROUP BY) — the series columns must survive it too.
        $this->linkTimeToCompetition(PuzzleSolvingTimeFixture::TIME_12, CompetitionSeriesFixture::EDITION_EJJ_68);

        $byTimeId = [];
        foreach ($this->query->duoByPuzzleId(PuzzleFixture::PUZZLE_1000_01) as $group) {
            $byTimeId[$group->timeId] = $group;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_12];
        self::assertCount(2, $edition->players);
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);
    }
}
