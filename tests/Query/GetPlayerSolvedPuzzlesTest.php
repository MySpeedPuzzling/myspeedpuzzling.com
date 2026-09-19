<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\SolvedPuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerSolvedPuzzlesTest extends KernelTestCase
{
    private GetPlayerSolvedPuzzles $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayerSolvedPuzzles::class);
        $this->database = $container->get(Connection::class);
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

    public function testSameDaySolvesAreOrderedChronologicallyByTrackedAt(): void
    {
        // PLAYER_ADMIN has 3 same-day solves on PUZZLE_1000_01: 5200s→4600s→4000s (improving)
        // Same-day solves must appear in trackedAt order, not by solve time
        $results = $this->query->soloByPlayerIdAndPuzzleId(
            PlayerFixture::PLAYER_ADMIN,
            PuzzleFixture::PUZZLE_1000_01,
        );

        // Find the three same-day entries
        $sameDayResults = array_values(array_filter(
            $results,
            static fn ($r) => in_array($r->timeId, [
                PuzzleSolvingTimeFixture::TIME_47_SAME_DAY_SLOW,
                PuzzleSolvingTimeFixture::TIME_48_SAME_DAY_MEDIUM,
                PuzzleSolvingTimeFixture::TIME_49_SAME_DAY_FAST,
            ], true),
        ));

        self::assertCount(3, $sameDayResults);
        // Must be in chronological order: slow (09:00) → medium (13:00) → fast (18:00)
        self::assertSame(PuzzleSolvingTimeFixture::TIME_47_SAME_DAY_SLOW, $sameDayResults[0]->timeId);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_48_SAME_DAY_MEDIUM, $sameDayResults[1]->timeId);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_49_SAME_DAY_FAST, $sameDayResults[2]->timeId);
    }

    public function testSoloByPlayerIdWithDateRangeExcludesNullFinishedAt(): void
    {
        // TIME_46_RELAX_NO_FINISHED_AT has finished_at=null, tracked_at=3 days ago
        // It belongs to PLAYER_REGULAR, PUZZLE_1000_02
        // When filtering by date range (monthly statistics), entries without finished_at should be excluded
        $dateFrom = new DateTimeImmutable('-60 days');
        $dateTo = new DateTimeImmutable('now');

        $results = $this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR, $dateFrom, $dateTo);

        $timeIds = array_map(fn($s) => $s->timeId, $results);
        self::assertNotContains(
            PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT,
            $timeIds,
            'Entries with null finished_at should be excluded from monthly/date-range statistics',
        );
    }

    public function testSoloByPlayerIdWithoutDateRangeIncludesNullFinishedAt(): void
    {
        // When querying all-time (no date range), entries without finished_at should still be included
        $results = $this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR);

        $timeIds = array_map(fn($s) => $s->timeId, $results);
        self::assertContains(
            PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT,
            $timeIds,
            'Entries with null finished_at should be included in all-time statistics',
        );
    }

    public function testSoloByPlayerIdExposesSeriesOfEditionAndKeepsStandaloneShape(): void
    {
        // TIME_36 (PLAYER_REGULAR, no competition) is pointed at an edition of the EJJ series.
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :timeId',
            ['competitionId' => CompetitionSeriesFixture::EDITION_EJJ_68, 'timeId' => PuzzleSolvingTimeFixture::TIME_36],
        );

        $byTimeId = [];
        foreach ($this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR) as $solvedPuzzle) {
            $byTimeId[$solvedPuzzle->timeId] = $solvedPuzzle;
        }

        $edition = $byTimeId[PuzzleSolvingTimeFixture::TIME_36];
        self::assertSame('EJJ #68 — February 2026', $edition->competitionName);
        self::assertSame('ejj-68-february-2026', $edition->competitionSlug);
        self::assertNull($edition->competitionShortcut);
        self::assertSame('Euro Jigsaw Jam', $edition->competitionSeriesName);
        self::assertSame('euro-jigsaw-jam-series', $edition->competitionSeriesSlug);
        self::assertNull($edition->competitionSeriesShortcut);

        // TIME_09 is linked to the standalone WJPC 2024 — the standalone shape is untouched.
        $standalone = $byTimeId[PuzzleSolvingTimeFixture::TIME_09];
        self::assertSame('WJPC 2024', $standalone->competitionName);
        self::assertSame('WJPC24', $standalone->competitionShortcut);
        self::assertSame('wjpc-2024', $standalone->competitionSlug);
        self::assertNull($standalone->competitionSeriesName);
        self::assertNull($standalone->competitionSeriesShortcut);
        self::assertNull($standalone->competitionSeriesSlug);
    }

    /**
     * Player profile and statistics read the solo, duo and team lists of one player in a row:
     * the player is checked once, and empty duo/team lists skip the team-members lookup
     * (it used to run as "WHERE id IN (NULL)").
     */
    public function testSoloDuoAndTeamListsOfOnePlayerCheckThePlayerOnce(): void
    {
        /** @var list<string> $playersWithoutDuoOrTeamTimes */
        $playersWithoutDuoOrTeamTimes = $this->database->fetchFirstColumn(
            "SELECT p.id FROM player p
             WHERE EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.player_id = p.id AND pst.puzzling_type = 'solo')
               AND NOT EXISTS (
                   SELECT 1 FROM puzzle_solving_time pst
                   WHERE pst.puzzling_type != 'solo'
                     AND (pst.player_id = p.id OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', p.id::text)))
               )
             ORDER BY p.id LIMIT 1",
        );
        self::assertNotEmpty($playersWithoutDuoOrTeamTimes, 'Fixtures need a solo-only player');
        $playerId = $playersWithoutDuoOrTeamTimes[0];

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        self::assertNotEmpty($this->query->soloByPlayerId($playerId));
        self::assertSame([], $this->query->duoByPlayerId($playerId));
        self::assertSame([], $this->query->teamByPlayerId($playerId));

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $queries = array_column($executed, 'sql');

        self::assertCount(1, array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT 1 FROM player')));
        self::assertCount(0, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'player_elem.ordinality')));
        // existence check + solo + duo + team
        self::assertCount(4, $queries);
    }

    public function testUnknownPlayerIsStillNotFoundAfterAnotherPlayerWasChecked(): void
    {
        $this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR);

        $this->expectException(PlayerNotFound::class);
        $this->query->duoByPlayerId('018d0000-0000-0000-0000-999999999999');
    }

    public function testTeamMembersAreStillLoadedForDuoTimes(): void
    {
        $duo = $this->query->duoByPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($duo);

        foreach ($duo as $solvedPuzzle) {
            self::assertNotNull($solvedPuzzle->players);
            self::assertCount(2, $solvedPuzzle->players);
        }
    }

    public function testGroupTimesWithAHiddenMemberAreDroppedForTheBlockerOnly(): void
    {
        // TIME_12 and TIME_41: PLAYER_REGULAR together with PLAYER_PRIVATE
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE);

        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $this->groupTimeIdsOf(PlayerFixture::PLAYER_REGULAR));
        self::assertContains(PuzzleFixture::PUZZLE_1000_03, $this->puzzleIdsOf(PlayerFixture::PLAYER_REGULAR));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $this->groupTimeIdsOf(PlayerFixture::PLAYER_REGULAR));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $filtered = $this->groupTimeIdsOf(PlayerFixture::PLAYER_REGULAR);
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_12, $filtered);
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_41, $filtered);
        // PUZZLE_1000_03 was solved in that group only
        self::assertNotContains(PuzzleFixture::PUZZLE_1000_03, $this->puzzleIdsOf(PlayerFixture::PLAYER_REGULAR));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->puzzleIdsOf(PlayerFixture::PLAYER_REGULAR));
        self::assertNull($this->query->byPuzzleIdAndPlayerId(PuzzleFixture::PUZZLE_1000_03, PlayerFixture::PLAYER_REGULAR));
        self::assertNotEmpty($this->query->soloByPlayerId(PlayerFixture::PLAYER_REGULAR));

        $this->expectException(PuzzleSolvingTimeNotFound::class);
        $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_12);
    }

    public function testViewersOwnGroupTimesWithAHiddenMemberStay(): void
    {
        // UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE, the partner of TIME_12 and TIME_41
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $own = $this->groupTimeIdsOf(PlayerFixture::PLAYER_REGULAR);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $own);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_41, $own);
        self::assertNotNull($this->query->byPuzzleIdAndPlayerId(PuzzleFixture::PUZZLE_1000_03, PlayerFixture::PLAYER_REGULAR));

        $detail = $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_12);
        self::assertTrue(Puzzler::listContainsPlayer($detail->players, PlayerFixture::PLAYER_PRIVATE));

        foreach ($this->query->duoByPlayerId(PlayerFixture::PLAYER_REGULAR) as $time) {
            self::assertNotNull($time->players, 'Members of the own group time are still loaded');
        }
    }

    public function testSoloTimeOfAHiddenPlayerIsNotFound(): void
    {
        // TIME_02 is a solo time of PLAYER_PRIVATE
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_02)->playerId);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame([], $this->query->soloByPlayerId(PlayerFixture::PLAYER_PRIVATE));

        $this->expectException(PuzzleSolvingTimeNotFound::class);
        $this->query->byTimeId(PuzzleSolvingTimeFixture::TIME_02);
    }

    /**
     * @return list<string>
     */
    private function puzzleIdsOf(string $playerId): array
    {
        return array_values(array_map(
            static fn (SolvedPuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->query->allByPlayerId($playerId),
        ));
    }

    /**
     * @return list<string>
     */
    private function groupTimeIdsOf(string $playerId): array
    {
        return array_values(array_map(
            static fn ($time): string => $time->timeId,
            [...$this->query->duoByPlayerId($playerId), ...$this->query->teamByPlayerId($playerId)],
        ));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
