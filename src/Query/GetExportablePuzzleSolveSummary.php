<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzleSolveSummary;

/**
 * One aggregate over the player's own results on the exported puzzles (docs/features/data-export.md). Solo results
 * by player, pair/team results through the registered membership - the index-friendly form of GetFirstTryTimes;
 * the tracker is a member of their team, so nothing is counted twice.
 */
readonly final class GetExportablePuzzleSolveSummary
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<string> $puzzleIds duplicates are fine
     * @return array<string, ExportablePuzzleSolveSummary> puzzle id => summary, only puzzles the player solved
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
WITH mine AS (
    SELECT pst.id
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzling_team_id IS NULL
        AND pst.puzzle_id = ANY(:puzzleIds)
    UNION
    SELECT pst.id
    FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id = :playerId
        AND pst.puzzle_id = ANY(:puzzleIds)
)
SELECT
    pst.puzzle_id,
    COUNT(*) AS solved_count,
    MIN(COALESCE(pst.finished_at, pst.tracked_at)) AS first_solved_at,
    MAX(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at,
    MIN(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo') AS best_solo_seconds
FROM mine
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
GROUP BY pst.puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, solved_count: int|string, first_solved_at: string, last_solved_at: string, best_solo_seconds: null|int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'puzzleIds' => '{' . implode(',', array_unique($puzzleIds)) . '}',
        ])->fetchAllAssociative();

        $summaries = [];

        foreach ($rows as $row) {
            $summaries[$row['puzzle_id']] = new ExportablePuzzleSolveSummary(
                solvedCount: (int) $row['solved_count'],
                firstSolvedAt: new DateTimeImmutable($row['first_solved_at']),
                lastSolvedAt: new DateTimeImmutable($row['last_solved_at']),
                bestSoloSeconds: $row['best_solo_seconds'] === null ? null : (int) $row['best_solo_seconds'],
            );
        }

        return $summaries;
    }
}
