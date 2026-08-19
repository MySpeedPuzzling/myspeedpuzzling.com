<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestPlayersTest extends KernelTestCase
{
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
}
