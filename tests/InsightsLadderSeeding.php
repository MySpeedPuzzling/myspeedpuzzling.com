<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * A small rating ladder for the insights batch tests (fixture players never reach a rating: it needs 20+ solvers per
 * puzzle). 24 players (1-22 public, 23-24 private) × 55 puzzles of 500 pieces, first attempts 100-599 days before
 * $now, repeat attempts of players 1-10 on puzzles 1-5. Plain SQL like LeaderboardSeeding: persisting entities would
 * dispatch PuzzleSolved per row. Every solve is at 03:00 and the runs at 12:00, so no age in days moves between runs
 * of a day.
 */
trait InsightsLadderSeeding
{
    private static function ladderPlayer(int $number): string
    {
        return sprintf('019b0000-0000-7000-8000-%012d', $number);
    }

    private static function ladderPuzzle(int $number): string
    {
        return sprintf('019b0001-0000-7000-8000-%012d', $number);
    }

    private function seedLadder(Connection $connection, DateTimeImmutable $now): void
    {
        $today = $now->format('Y-m-d');

        $connection->executeStatement(
            "INSERT INTO player (id, code, name, registered_at, is_private)
            SELECT CAST('019b0000-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 'insladder' || n, 'Insights Solver ' || n, CAST(:today AS timestamp) - INTERVAL '3 years', n > 22
            FROM generate_series(1, 24) AS n",
            ['today' => $today],
        );

        $connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available)
            SELECT CAST('019b0001-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 500, 'Insights Puzzle ' || n, true, true
            FROM generate_series(1, 55) AS n",
        );

        $this->seedSolves($connection, $now, [
            // First attempts of everybody on every puzzle, 100-599 days ago
            'SELECT p, z, 1500 + 60 * p + 23 * z + ((p * 37 + z * 11) % 50) * 9, 100 + (p * 7 + z * 13) % 500, true FROM generate_series(1, 24) AS p CROSS JOIN generate_series(1, 55) AS z',
            // Attempts 2 and 3 of players 1-10 on puzzles 1-5
            'SELECT p, z, (1500 + 60 * p + 23 * z) * 85 / 100, 60, false FROM generate_series(1, 10) AS p CROSS JOIN generate_series(1, 5) AS z',
            'SELECT p, z, (1500 + 60 * p + 23 * z) * 75 / 100, 30, false FROM generate_series(1, 10) AS p CROSS JOIN generate_series(1, 5) AS z',
        ]);
    }

    /**
     * @param list<string> $solves SELECTs of (player number, puzzle number, seconds, days ago, first attempt)
     */
    private function seedSolves(Connection $connection, DateTimeImmutable $now, array $solves): void
    {
        foreach ($solves as $solve) {
            $connection->executeStatement(
                "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt)
                SELECT gen_random_uuid(),
                    CAST('019b0000-0000-7000-8000-' || lpad(s.p::text, 12, '0') AS uuid),
                    CAST('019b0001-0000-7000-8000-' || lpad(s.z::text, 12, '0') AS uuid),
                    s.seconds,
                    CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => s.days_ago),
                    CAST(:today AS timestamp) + INTERVAL '3 hours' - make_interval(days => s.days_ago),
                    true,
                    s.first_attempt
                FROM ({$solve}) AS s (p, z, seconds, days_ago, first_attempt)",
                ['today' => $now->format('Y-m-d')],
            );
        }
    }
}
