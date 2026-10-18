<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;

/**
 * The player's solo puzzles last solved before a moment, with the best time - the re-solve radar's candidates (the
 * same results the personal predictions are built on: solo, not suspicious, timed, boxed). One statement.
 */
readonly final class GetInsightsResolveCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array{puzzle: InsightsPuzzleRef, best_seconds: int, last_solved_at: DateTimeImmutable, attempts: int, on_shelf: bool, solo_count: int}>
     */
    public function lastSolvedBefore(string $playerId, DateTimeImmutable $before, DateTimeImmutable $now): array
    {
        $puzzleColumns = InsightsPuzzleRef::sqlColumns();
        $onShelf = GetInsightsShelf::sqlOnShelf('p.id');

        $query = <<<SQL
WITH solves AS (
    SELECT
        pst.puzzle_id,
        MIN(pst.seconds_to_solve) AS best_seconds,
        MAX(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at,
        COUNT(*) AS attempts
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve > 0
        AND pst.unboxed = false
    GROUP BY pst.puzzle_id
    HAVING MAX(COALESCE(pst.finished_at, pst.tracked_at)) < CAST(:before AS timestamp)
)
SELECT
    s.best_seconds,
    s.last_solved_at,
    s.attempts,
    {$onShelf} AS on_shelf,
    COALESCE(ps.solved_times_solo_count, 0) AS solo_count,
    {$puzzleColumns}
FROM solves s
JOIN puzzle p ON p.id = s.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
ORDER BY p.id
SQL;

        /** @var list<array{best_seconds: int|string, last_solved_at: string, attempts: int|string, on_shelf: bool|int|string, solo_count: int|string, puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'before' => $before->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        $candidates = [];

        foreach ($rows as $row) {
            $puzzle = InsightsPuzzleRef::fromRow($row);

            if ($puzzle === null) {
                continue;
            }

            $candidates[] = [
                'puzzle' => $puzzle,
                'best_seconds' => (int) $row['best_seconds'],
                'last_solved_at' => new DateTimeImmutable($row['last_solved_at']),
                'attempts' => (int) $row['attempts'],
                'on_shelf' => $row['on_shelf'] === true || $row['on_shelf'] === 1 || $row['on_shelf'] === '1' || $row['on_shelf'] === 't',
                'solo_count' => (int) $row['solo_count'],
            ];
        }

        return $candidates;
    }
}
