<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\AchievementSqlRules;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Services\Badges\AchievementFirstMetDates;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Competitor achievement's events (AchievementSqlRules::COMPETITOR_EVENTS) are read by the stats snapshot (counted)
 * and by the history replay (AchievementFirstMetDates, dated). Whatever the statement starts from, it must return the
 * same competitions with the same `at` for every player and every day.
 *
 * Seeded players take part in EVERY fixture competition - the fixtures hold in-person and online, ended and coming,
 * undated, draft, rejected, unapproved, editions of approved / unapproved / draft series, workshops and swaps - each one
 * in another way: connected early or only after the end, registered without a connection, removed, with an own result,
 * a suspicious one, only in a pair result a teammate linked, several entries at once.
 */
final class CompetitorEventsParityTest extends KernelTestCase
{
    private const array TODAYS = ['2000-01-01', '2024-06-15', '2025-12-31', '2100-01-01'];

    private Connection $database;

    /** @var list<string> */
    private array $competitionIds;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);

        /** @var list<string> $competitionIds */
        $competitionIds = $this->database->fetchFirstColumn('SELECT CAST(id AS TEXT) FROM competition ORDER BY id');
        $this->competitionIds = $competitionIds;
    }

    public function testEveryFixturePlayerHasTheSameCompetitorEvents(): void
    {
        $this->assertSameForEveryPlayer();
    }

    public function testPlayersTakingPartInEveryCompetitionInEveryWayHaveTheSameCompetitorEvents(): void
    {
        $this->seedParticipation();

        $this->assertSameForEveryPlayer();
    }

    public function testEventKindsInheritedFromTheSeriesDecideTheSameWay(): void
    {
        $this->seedParticipation();

        $this->database->executeStatement("UPDATE competition_series SET event_types = '[\"workshop\"]'");
        $this->database->executeStatement("UPDATE competition SET event_types = '[]' WHERE series_id IS NOT NULL");
        $this->database->executeStatement("UPDATE competition SET event_types = '[\"marathon\", \"competition\"]' WHERE series_id IS NULL AND MOD(get_byte(decode(md5(CAST(id AS TEXT)), 'hex'), 0), 2) = 0");
        $this->assertSameForEveryPlayer();

        $this->database->executeStatement("UPDATE competition_series SET event_types = '[\"competition\"]'");
        $this->database->executeStatement("UPDATE competition SET event_types = '[\"puzzle_swap\"]' WHERE series_id IS NOT NULL AND MOD(get_byte(decode(md5(CAST(id AS TEXT)), 'hex'), 0), 2) = 0");
        $this->assertSameForEveryPlayer();
    }

    private function assertSameForEveryPlayer(): void
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn('SELECT CAST(id AS TEXT) FROM player ORDER BY id');
        $someoneHasEvents = false;

        foreach ($playerIds as $playerId) {
            foreach (self::TODAYS as $today) {
                $before = $this->rows(CompetitorEventsBeforeOwnRows::COMPETITOR_EVENTS, $playerId, $today);
                $someoneHasEvents = $someoneHasEvents || $before !== [];

                self::assertSame($before, $this->rows(AchievementSqlRules::COMPETITOR_EVENTS, $playerId, $today), "player {$playerId}, today {$today}");
            }
        }

        self::assertTrue($someoneHasEvents);

        // Both readers embed it: the snapshot as a CTE (today = the clock's), the replay as is
        $today = GetPlayerStatsSnapshot::today(self::getContainer()->get(ClockInterface::class));
        self::assertSame(TestClock::now()->format('Y-m-d'), $today);
        $snapshot = self::getContainer()->get(GetPlayerStatsSnapshot::class);
        $replay = self::getContainer()->get(AchievementFirstMetDates::class);

        foreach ($playerIds as $playerId) {
            self::assertSame(
                count($this->rows(CompetitorEventsBeforeOwnRows::COMPETITOR_EVENTS, $playerId, $today)),
                $snapshot->forPlayer($playerId)->competitorEvents,
                "snapshot of {$playerId}",
            );
            $replay->forPlayer($playerId);
        }
    }

    /**
     * @return list<array{competition_id: string, at: string}>
     */
    private function rows(string $sql, string $playerId, string $today): array
    {
        /** @var list<array{competition_id: string, at: string}> $rows */
        $rows = $this->database->fetchAllAssociative($sql, ['playerId' => $playerId, 'today' => $today]);
        usort($rows, static fn (array $a, array $b): int => [$a['competition_id'], $a['at']] <=> [$b['competition_id'], $b['at']]);

        return $rows;
    }

    private function seedParticipation(): void
    {
        $connectedEarly = $this->seedPlayer('competitor-early');
        $connectedLate = $this->seedPlayer('competitor-late');
        $registeredOnly = $this->seedPlayer('competitor-registered');
        $ownResults = $this->seedPlayer('competitor-results');
        $teammate = $this->seedPlayer('competitor-teammate');
        $removedOrSuspicious = $this->seedPlayer('competitor-removed');
        $everything = $this->seedPlayer('competitor-everything');

        foreach ($this->competitionIds as $index => $competitionId) {
            $this->insertParticipant($competitionId, $connectedEarly, connectedAt: '2019-01-01 10:00:00');
            $this->insertParticipant($competitionId, $connectedLate, connectedAt: '2099-03-01 10:00:00', registeredAt: '2018-01-01 10:00:00');
            $this->insertParticipant($competitionId, $registeredOnly, connectedAt: null, registeredAt: $index % 2 === 0 ? '2018-05-01 10:00:00' : null);

            $this->insertSolve($ownResults, $index % 3 === 0 ? '2019-06-01 12:00:00' : '2099-06-01 12:00:00', $competitionId);
            $this->insertSolve($ownResults, '2099-07-01 12:00:00', $competitionId, suspicious: $index % 2 === 0);

            $this->insertSolve($ownResults, '2019-08-01 12:00:00', $competitionId, team: [$ownResults, $teammate]);

            $this->insertParticipant($competitionId, $removedOrSuspicious, connectedAt: '2019-01-01 10:00:00', deleted: true);
            $this->insertSolve($removedOrSuspicious, '2019-06-01 12:00:00', $competitionId, suspicious: true);

            // Several entries of one player in one competition, the earliest decides
            $this->insertParticipant($competitionId, $everything, connectedAt: '2099-01-01 10:00:00');
            $this->insertParticipant($competitionId, $everything, connectedAt: $index % 2 === 0 ? '2020-02-02 10:00:00' : '2099-02-02 10:00:00');
            $this->insertParticipant($competitionId, $everything, connectedAt: '2010-01-01 10:00:00', deleted: true);
            $this->insertSolve($everything, $index % 4 === 0 ? '2019-03-03 12:00:00' : '2099-04-04 12:00:00', $competitionId);
            $this->insertSolve($connectedEarly, '2018-01-01 12:00:00', $competitionId, team: [$connectedEarly, $everything, $teammate]);
        }

        // Results linked to no competition, or only to a series, never count
        $this->insertSolve($ownResults, '2019-06-01 12:00:00', null);
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_series_id = (SELECT id FROM competition_series ORDER BY id LIMIT 1) WHERE competition_id IS NULL AND player_id = :player',
            ['player' => $ownResults],
        );
    }

    private function seedPlayer(string $code): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            'INSERT INTO player (id, code, name, registered_at) VALUES (:id, :code, :code, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'code' => $code],
        );

        return $id;
    }

    private function insertParticipant(string $competitionId, string $playerId, null|string $connectedAt, null|string $registeredAt = null, bool $deleted = false): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, player_id, connected_at, registered_at, deleted_at)
             VALUES (:id, 'Test participant', NULL, :competitionId, 'self_joined', :playerId, :connectedAt, :registeredAt, :deletedAt)",
            [
                'id' => Uuid::uuid7()->toString(),
                'competitionId' => $competitionId,
                'playerId' => $playerId,
                'connectedAt' => $connectedAt,
                'registeredAt' => $registeredAt,
                'deletedAt' => $deleted ? '2020-01-02 10:00:00' : null,
            ],
        );
    }

    /**
     * @param null|list<string> $team
     */
    private function insertSolve(string $playerId, string $trackedAt, null|string $competitionId, bool $suspicious = false, null|array $team = null): void
    {
        $teamJson = null;

        if ($team !== null) {
            $teamJson = json_encode([
                'team_id' => null,
                'puzzlers' => array_map(static fn (string $id): array => ['player_id' => $id, 'player_name' => null, 'player_code' => null, 'player_country' => null, 'is_private' => false], $team),
            ], JSON_THROW_ON_ERROR);
        }

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot, competition_id)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :trackedAt, true, :team, :trackedAt,
                 NULL, NULL, false, false, :puzzlersCount, :puzzlingType, :suspicious, 500, :competitionId)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => PuzzleFixture::PUZZLE_500_04,
                'trackedAt' => $trackedAt,
                'team' => $teamJson,
                'puzzlersCount' => $team === null ? 1 : count($team),
                'puzzlingType' => $team === null ? 'solo' : (count($team) === 2 ? 'duo' : 'team'),
                'suspicious' => (int) $suspicious,
                'competitionId' => $competitionId,
            ],
        );
    }
}
