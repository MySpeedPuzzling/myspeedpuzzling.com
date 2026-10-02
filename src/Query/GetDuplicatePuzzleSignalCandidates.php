<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalCandidate;

/**
 * Puzzle records that are most likely one puzzle entered twice, site-wide (docs/features/duplicate-results.md,
 * Layer 4 "Catalogue signal"): the same person logged the same time to the second on the same day on two puzzles
 * with the same piece count. Judged per person like GetDuplicateCandidates - solo results by their tracker, pair/team
 * results by every registered member; guests are a name, not a person.
 *
 * One statement for the daily detection only, never on a page view: a full pass over the results (no index can
 * narrow "any two puzzles"), the self-join is a merge join on person + seconds + day. ~1 s on a copy of production
 * (523k results, 458 pairs) - a third of the pairs are chance, which the admin dismisses.
 */
readonly final class GetDuplicatePuzzleSignalCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<DuplicatePuzzleSignalCandidate>
     */
    public function all(): array
    {
        $query = <<<SQL
WITH person_result AS (
    SELECT pst.id, pst.player_id AS person_id, pst.puzzle_id, pst.seconds_to_solve,
        COALESCE(pst.finished_at, pst.tracked_at)::date AS solved_day
    FROM puzzle_solving_time pst
    WHERE pst.puzzling_team_id IS NULL
        AND pst.seconds_to_solve IS NOT NULL
    UNION ALL
    SELECT pst.id, member.player_id, pst.puzzle_id, pst.seconds_to_solve,
        COALESCE(pst.finished_at, pst.tracked_at)::date
    FROM puzzle_solving_time pst
    INNER JOIN puzzling_team_member member ON member.team_id = pst.puzzling_team_id AND member.player_id IS NOT NULL
    WHERE pst.seconds_to_solve IS NOT NULL
),
twin AS (
    SELECT a.puzzle_id AS puzzle_a_id, b.puzzle_id AS puzzle_b_id, a.person_id, a.id AS a_id, b.id AS b_id,
        a.seconds_to_solve, a.solved_day
    FROM person_result a
    INNER JOIN person_result b
        ON b.person_id = a.person_id
        AND b.seconds_to_solve = a.seconds_to_solve
        AND b.solved_day = a.solved_day
        AND b.puzzle_id > a.puzzle_id
    INNER JOIN puzzle puzzle_a ON puzzle_a.id = a.puzzle_id
    INNER JOIN puzzle puzzle_b ON puzzle_b.id = b.puzzle_id
    WHERE puzzle_b.pieces_count = puzzle_a.pieces_count
)
SELECT
    puzzle_a_id,
    puzzle_b_id,
    COUNT(DISTINCT a_id) + COUNT(DISTINCT b_id) AS matching_results,
    COUNT(DISTINCT person_id) AS matching_people,
    (array_agg(person_id ORDER BY solved_day DESC, person_id, a_id))[1] AS example_player_id,
    (array_agg(seconds_to_solve ORDER BY solved_day DESC, person_id, a_id))[1] AS example_seconds,
    MAX(solved_day) AS example_day
FROM twin
GROUP BY puzzle_a_id, puzzle_b_id
ORDER BY puzzle_a_id, puzzle_b_id
SQL;

        /** @var list<array{puzzle_a_id: string, puzzle_b_id: string, matching_results: int|string, matching_people: int|string, example_player_id: string, example_seconds: int|string, example_day: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query);

        return array_map(
            static fn (array $row): DuplicatePuzzleSignalCandidate => new DuplicatePuzzleSignalCandidate(
                puzzleAId: $row['puzzle_a_id'],
                puzzleBId: $row['puzzle_b_id'],
                matchingResults: (int) $row['matching_results'],
                matchingPeople: (int) $row['matching_people'],
                examplePlayerId: $row['example_player_id'],
                exampleSeconds: (int) $row['example_seconds'],
                exampleDay: new DateTimeImmutable($row['example_day']),
            ),
            $rows,
        );
    }
}
