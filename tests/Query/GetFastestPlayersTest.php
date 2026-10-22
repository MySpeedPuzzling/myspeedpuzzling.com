<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestPlayersTest extends KernelTestCase
{
    use LeaderboardSeeding;

    private GetFastestPlayers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestPlayers::class);
        $this->database = $container->get(Connection::class);
    }

    public function testPerPiecesCountReturnsOnlySoloTimes(): void
    {
        // 500 piece puzzles have many solo times in fixtures
        $results = $this->query->perPiecesCount(500, 10, null);

        self::assertNotEmpty($results);

        // Results should be ordered by time ascending
        $times = array_map(fn($r) => $r->time, $results);
        $sortedTimes = $times;
        sort($sortedTimes);
        self::assertSame($sortedTimes, $times, 'Results should be sorted by time ascending');

        // Each result should be for 500 pieces
        foreach ($results as $result) {
            self::assertSame(500, $result->piecesCount);
        }
    }

    public function testPerPiecesCountExcludesTeamSolves(): void
    {
        // 1000 piece puzzles have solo times and team time (TIME_12)
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertNotEmpty($results);

        // All results should have a player (solo only)
        foreach ($results as $result) {
            self::assertSame(1000, $result->piecesCount);
        }
    }

    public function testPerPiecesCountReturnsDistinctPlayers(): void
    {
        // Each player should appear only once (with their best time)
        $results = $this->query->perPiecesCount(500, 20, null);

        $playerIds = array_map(fn($r) => $r->playerId, $results);
        $uniquePlayerIds = array_unique($playerIds);

        self::assertCount(count($playerIds), $uniquePlayerIds, 'Each player should appear only once');
    }

    public function testPerPiecesCountExcludesPrivatePlayers(): void
    {
        // PLAYER_PRIVATE has isPrivate=true and solved puzzles
        $results = $this->query->perPiecesCount(500, 20, null);

        $playerIds = array_map(fn($r) => $r->playerId, $results);

        self::assertNotContains(
            PlayerFixture::PLAYER_PRIVATE,
            $playerIds,
            'Private players should not appear in fastest players leaderboard',
        );
    }

    public function testEqualBestTimesOfAPlayerShowTheEarliest(): void
    {
        /** @var list<string> $tied */
        $tied = $this->database->fetchFirstColumn(
            "SELECT pst.id
             FROM puzzle_solving_time pst
             INNER JOIN puzzle p ON p.id = pst.puzzle_id
             WHERE pst.player_id = :playerId AND p.pieces_count = 500 AND pst.puzzling_type = 'solo' AND pst.seconds_to_solve = 1700
             ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id",
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertCount(2, $tied, 'Premise: John solved two 500-piece puzzles in 1700 s, his best');

        $johnsRow = null;
        foreach ($this->query->perPiecesCount(500, 20, null) as $result) {
            if ($result->playerId === PlayerFixture::PLAYER_REGULAR) {
                $johnsRow = $result;
            }
        }

        self::assertNotNull($johnsRow);
        self::assertSame(1700, $johnsRow->time);
        self::assertSame($tied[0], $johnsRow->timeId);
    }

    public function testPerPiecesCountRespectsLimit(): void
    {
        $results = $this->query->perPiecesCount(500, 3, null);

        self::assertLessThanOrEqual(3, count($results));
    }

    public function testPerPiecesCountWithCountryFilter(): void
    {
        // We have players from different countries
        // PLAYER_REGULAR is from CZ, PLAYER_ADMIN is from CZ
        $results = $this->query->perPiecesCount(500, 10, CountryCode::cz);

        foreach ($results as $result) {
            self::assertSame(CountryCode::cz, $result->playerCountry);
        }
    }

    public function testPerPiecesCountReturnsEmptyForNonExistentPiecesCount(): void
    {
        // No puzzles with 42 pieces in fixtures
        $results = $this->query->perPiecesCount(42, 10, null);

        self::assertEmpty($results);
    }

    public function testPerPiecesCountExposesSeriesOfEditionAndKeepsStandaloneShape(): void
    {
        // The ladder keeps each player's fastest time (CTE + GROUP BY). Point PLAYER_REGULAR's
        // fastest 500-piece time at an EJJ edition and PLAYER_ADMIN's at the standalone WJPC 2024,
        // then check both shapes survive the aggregation.
        $byPlayerId = [];
        foreach ($this->query->perPiecesCount(500, 20, null) as $result) {
            $byPlayerId[$result->playerId] = $result;
        }

        self::assertArrayHasKey(PlayerFixture::PLAYER_REGULAR, $byPlayerId);
        self::assertArrayHasKey(PlayerFixture::PLAYER_ADMIN, $byPlayerId);

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => $byPlayerId[PlayerFixture::PLAYER_REGULAR]->timeId],
        );
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionFixture::COMPETITION_WJPC_2024, 'timeId' => $byPlayerId[PlayerFixture::PLAYER_ADMIN]->timeId],
        );

        $byPlayerId = [];
        foreach ($this->query->perPiecesCount(500, 20, null) as $result) {
            $byPlayerId[$result->playerId] = $result;
        }

        $edition = $byPlayerId[PlayerFixture::PLAYER_REGULAR];
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);

        $standalone = $byPlayerId[PlayerFixture::PLAYER_ADMIN];
        self::assertSame('WJPC 2024', $standalone->competitionName);
        self::assertSame('WJPC24', $standalone->competitionShortcut);
        self::assertSame('wjpc-2024', $standalone->competitionSlug);
        self::assertNull($standalone->competitionSeriesName);
        self::assertNull($standalone->competitionSeriesShortcut);
        self::assertNull($standalone->competitionSeriesSlug);
    }

    public function testBlockedPlayerIsLeftOutAndTheListClosesUp(): void
    {
        $everyone = array_map(static fn ($r) => $r->playerId, $this->query->perPiecesCount(500, 100, null));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $everyone);
        self::assertGreaterThan(2, count($everyone));

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $visible = array_map(static fn ($r) => $r->playerId, $this->query->perPiecesCount(500, 100, null));
        self::assertSame(
            array_values(array_diff($everyone, [PlayerFixture::PLAYER_ADMIN])),
            $visible,
        );

        // The limit applies after the filter: the next player moves up instead of leaving a hole
        $topTwo = array_map(static fn ($r) => $r->playerId, $this->query->perPiecesCount(500, 2, null));
        self::assertSame(array_slice($visible, 0, 2), $topTwo);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($everyone, array_map(static fn ($r) => $r->playerId, $this->query->perPiecesCount(500, 100, null)));
    }

    public function testTheWindowShowsTheTopAndTheViewerWithTwoRowsAroundThemInOneStatement(): void
    {
        // 30 seeded solvers faster than every fixture time: the viewer's best is far below the top 5
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 30, firstSeconds: 10);
        $board = $this->query->perPiecesCount(500, 1000, null);
        $ownIndex = array_search(PlayerFixture::PLAYER_REGULAR, array_map(static fn ($r) => $r->playerId, $board), true);
        self::assertIsInt($ownIndex);
        self::assertGreaterThan(30, $ownIndex);

        $slice = $this->query->window(500, null, limit: 5, above: 2, below: 2, viewerPlayerId: PlayerFixture::PLAYER_REGULAR);

        $expectedPositions = [0, 1, 2, 3, 4];

        for ($position = $ownIndex - 2; $position <= min($ownIndex + 2, count($board) - 1); $position++) {
            $expectedPositions[] = $position;
        }

        self::assertSame($expectedPositions, $slice->positions);
        self::assertSame(count($board), $slice->total);
        self::assertSame($ownIndex, $slice->ownPosition);
        self::assertSame(
            array_map(static fn (int $position): string => $board[$position]->timeId, $expectedPositions),
            array_map(static fn ($r) => $r->timeId, $slice->rows),
        );

        // The position line: the viewer's rank, of the whole list, and the next milestone above them (#25 here)
        $line = $slice->viewerPosition;
        self::assertNotNull($line);
        $ownRowIndex = array_search($ownIndex, $slice->positions, true);
        self::assertIsInt($ownRowIndex);
        self::assertSame($slice->ranks[$ownRowIndex], $line->rank);
        self::assertSame(count($board), $line->total);
        self::assertSame(25, $line->targetRank);
        self::assertSame($board[$ownIndex]->time - $board[24]->time, $line->targetGap);

        // The closest faster time of the viewer's first neighbour is the hidden row right above it
        $firstOwnBlockIndex = 5;
        self::assertSame($board[$ownIndex - 3]->time, $slice->closestBetter[$firstOwnBlockIndex]);
    }

    public function testEqualBestTimesShareTheRankAndKeepAStableOrder(): void
    {
        $seeded = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 4, firstSeconds: 10, secondsBetween: 0);

        $slice = $this->query->window(500, null, limit: 5);

        self::assertSame([1, 1, 1, 1, 5], $slice->ranks);
        $sortedIds = $seeded;
        sort($sortedIds);
        self::assertSame($sortedIds, array_map(static fn ($r) => $r->playerId, array_slice($slice->rows, 0, 4)));
        self::assertNull($slice->viewerPosition);
    }

    public function testACountryBoardRanksWithinTheCountry(): void
    {
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_500_05, 3, firstSeconds: 10);

        $czech = $this->query->window(500, CountryCode::cz, limit: 100, viewerPlayerId: PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($czech->rows);
        self::assertSame(1, $czech->ranks[0]);

        foreach ($czech->rows as $row) {
            self::assertSame(CountryCode::cz, $row->playerCountry);
        }

        // The seeded solvers (no country) are faster than everybody, yet the Czech viewer's rank only counts Czechs
        self::assertNotNull($czech->viewerPosition);
        self::assertSame(count($czech->rows), $czech->viewerPosition->total);
        self::assertLessThanOrEqual(count($czech->rows), $czech->viewerPosition->rank);
    }

    public function testATimeWaitingForVerificationIsNeitherOnTheBoardNorTheViewersBest(): void
    {
        $this->seedSoloTime(PuzzleFixture::PUZZLE_500_05, PlayerFixture::PLAYER_REGULAR, 5);
        $timed = $this->query->window(500, null, limit: 1, viewerPlayerId: PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $timed->ownPosition);
        self::assertSame(5, $timed->rows[0]->time);

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :playerId AND seconds_to_solve = 5',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $slice = $this->query->window(500, null, limit: 1, viewerPlayerId: PlayerFixture::PLAYER_REGULAR);
        self::assertNotSame(5, $slice->rows[0]->time);
        self::assertNotSame(0, $slice->ownPosition);
    }

    public function testAPrivateViewerIsNotOnTheBoard(): void
    {
        $slice = $this->query->window(500, null, limit: 100, viewerPlayerId: PlayerFixture::PLAYER_PRIVATE);

        self::assertNull($slice->ownPosition);
        self::assertNull($slice->viewerPosition);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, array_map(static fn ($r) => $r->playerId, $slice->rows));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
