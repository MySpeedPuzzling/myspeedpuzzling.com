<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Seeds a puzzle leaderboard longer than PuzzleTimes::DEFAULT_LIMIT - the fixtures have
 * at most five solvers per puzzle. Plain SQL on purpose: persisting the entities would
 * dispatch a PuzzleSolved event (statistics, notifications, insights) for every row.
 * DAMA rolls the rows back with the test.
 */
trait LeaderboardSeeding
{
    /**
     * One solo time per new player; solver n (1-based) solves in $firstSeconds + n * $secondsBetween
     * seconds, so on a puzzle without other times solver n ranks n-th. The times were finished a day ago.
     *
     * @return list<string> the new players' ids, fastest first
     */
    protected function seedSoloSolvers(
        string $puzzleId,
        int $count,
        int $firstSeconds = 5000,
        bool $firstAttempt = false,
        int $secondsBetween = 1,
        null|string $country = null,
    ): array {
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            <<<SQL
INSERT INTO player (id, code, name, registered_at, country)
SELECT gen_random_uuid(), 'leaderboard' || n, 'Leaderboard Solver ' || n, NOW() - INTERVAL '1 year', :country
FROM generate_series(1, :count) AS n
SQL,
            ['count' => $count, 'country' => $country],
            ['count' => ParameterType::INTEGER],
        );

        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
SELECT gen_random_uuid(), player.id, :puzzleId, :firstSeconds + n * :secondsBetween, NOW() - INTERVAL '1 day', NOW() - INTERVAL '1 day', true, :firstAttempt
FROM generate_series(1, :count) AS n
INNER JOIN player ON player.code = 'leaderboard' || n
SQL,
            [
                'puzzleId' => $puzzleId,
                'count' => $count,
                'firstSeconds' => $firstSeconds,
                'secondsBetween' => $secondsBetween,
                'firstAttempt' => $firstAttempt,
            ],
            [
                'count' => ParameterType::INTEGER,
                'firstSeconds' => ParameterType::INTEGER,
                'secondsBetween' => ParameterType::INTEGER,
                'firstAttempt' => ParameterType::BOOLEAN,
            ],
        );

        /** @var list<string> $playerIds */
        $playerIds = $database->fetchFirstColumn(
            <<<SQL
SELECT player.id
FROM generate_series(1, :count) AS n
INNER JOIN player ON player.code = 'leaderboard' || n
ORDER BY n
SQL,
            ['count' => $count],
            ['count' => ParameterType::INTEGER],
        );

        return $playerIds;
    }

    /**
     * A solo time of an existing player - finished two days ago, so it leads any seeded solver with the same time
     */
    protected function seedSoloTime(string $puzzleId, string $playerId, int $seconds, bool $firstAttempt = false): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
VALUES (gen_random_uuid(), :playerId, :puzzleId, :seconds, NOW() - INTERVAL '2 days', NOW() - INTERVAL '2 days', true, :firstAttempt)
SQL,
            ['playerId' => $playerId, 'puzzleId' => $puzzleId, 'seconds' => $seconds, 'firstAttempt' => $firstAttempt],
            ['seconds' => ParameterType::INTEGER, 'firstAttempt' => ParameterType::BOOLEAN],
        );
    }
}
