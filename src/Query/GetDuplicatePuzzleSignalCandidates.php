<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalCandidate;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicatePuzzleSignalScoring;

/**
 * Puzzle records that are most likely one puzzle entered twice, site-wide (docs/features/duplicate-results.md,
 * Layer 4 "Catalogue signal"): the same person logged the same time to the second on the same day on two puzzles
 * with the same piece count. Judged per person like GetDuplicateCandidates - solo results by their tracker, pair/team
 * results by every registered member; guests are a name, not a person.
 *
 * Every pair also carries what DuplicatePuzzleSignalScoring weighs: both records' names and codes, how alike the names are
 * (each name of one record - main title and every other name - against each name of the other, unaccented and lower
 * case: the best trigram similarity, or one inside the other), brand, approval, results, when the records were added.
 *
 * One statement for the daily detection only, never on a page view: a full pass over the results (no index can
 * narrow "any two puzzles"), the self-join is a merge join on person + seconds + day. ~1 s on a copy of production
 * (523k results, 458 pairs); the evidence is looked up for those pairs only.
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
),
pair AS (
    SELECT
        puzzle_a_id,
        puzzle_b_id,
        COUNT(DISTINCT a_id) + COUNT(DISTINCT b_id) AS matching_results,
        COUNT(DISTINCT person_id) AS matching_people,
        (array_agg(person_id ORDER BY solved_day DESC, person_id, a_id))[1] AS example_player_id,
        (array_agg(seconds_to_solve ORDER BY solved_day DESC, person_id, a_id))[1] AS example_seconds,
        MAX(solved_day) AS example_day,
        MIN(solved_day) AS first_day
    FROM twin
    GROUP BY puzzle_a_id, puzzle_b_id
)
SELECT
    pair.puzzle_a_id,
    pair.puzzle_b_id,
    pair.matching_results,
    pair.matching_people,
    pair.example_player_id,
    pair.example_seconds,
    pair.example_day,
    puzzle_a.name AS puzzle_a_name,
    puzzle_b.name AS puzzle_b_name,
    puzzle_a.ean AS puzzle_a_ean,
    puzzle_a.identification_number AS puzzle_a_code,
    puzzle_b.ean AS puzzle_b_ean,
    puzzle_b.identification_number AS puzzle_b_code,
    names.similarity AS name_similarity,
    names.contained AS name_contained,
    puzzle_a.manufacturer_id = puzzle_b.manufacturer_id AS same_brand,
    puzzle_a.approved AND puzzle_b.approved AS both_approved,
    LEAST(COALESCE(statistics_a.solved_times_count, 0), COALESCE(statistics_b.solved_times_count, 0)) AS fewer_results,
    GREATEST(COALESCE(statistics_a.solved_times_count, 0), COALESCE(statistics_b.solved_times_count, 0)) AS more_results,
    ABS(EXTRACT(EPOCH FROM puzzle_a.added_at - puzzle_b.added_at))::int AS added_seconds_apart,
    pair.first_day - GREATEST(puzzle_a.added_at, puzzle_b.added_at)::date AS days_from_newer_record_to_first_match
FROM pair
INNER JOIN puzzle puzzle_a ON puzzle_a.id = pair.puzzle_a_id
INNER JOIN puzzle puzzle_b ON puzzle_b.id = pair.puzzle_b_id
LEFT JOIN puzzle_statistics statistics_a ON statistics_a.puzzle_id = puzzle_a.id
LEFT JOIN puzzle_statistics statistics_b ON statistics_b.puzzle_id = puzzle_b.id
CROSS JOIN LATERAL (
    SELECT
        COALESCE(MAX(similarity(name_a, name_b)), 0) AS similarity,
        COALESCE(BOOL_OR(
            (length(name_b) >= :containedMinLength AND strpos(name_a, name_b) > 0)
            OR (length(name_a) >= :containedMinLength AND strpos(name_b, name_a) > 0)
        ), false) AS contained
    FROM unnest(ARRAY[lower(immutable_unaccent(puzzle_a.name))] || ARRAY(SELECT lower(immutable_unaccent(other ->> 'name')) FROM jsonb_array_elements(puzzle_a.alternative_names) AS other)) AS name_a
    CROSS JOIN unnest(ARRAY[lower(immutable_unaccent(puzzle_b.name))] || ARRAY(SELECT lower(immutable_unaccent(other ->> 'name')) FROM jsonb_array_elements(puzzle_b.alternative_names) AS other)) AS name_b
    WHERE name_a <> '' AND name_b <> ''
) names
ORDER BY pair.puzzle_a_id, pair.puzzle_b_id
SQL;

        /** @var list<array{puzzle_a_id: string, puzzle_b_id: string, matching_results: int|string, matching_people: int|string, example_player_id: string, example_seconds: int|string, example_day: string, puzzle_a_name: string, puzzle_b_name: string, puzzle_a_ean: null|string, puzzle_a_code: null|string, puzzle_b_ean: null|string, puzzle_b_code: null|string, name_similarity: float|string, name_contained: bool, same_brand: null|bool, both_approved: bool, fewer_results: int|string, more_results: int|string, added_seconds_apart: null|int|string, days_from_newer_record_to_first_match: null|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'containedMinLength' => DuplicatePuzzleSignalScoring::NAME_CONTAINED_MIN_LENGTH,
        ]);

        return array_map(
            static fn (array $row): DuplicatePuzzleSignalCandidate => new DuplicatePuzzleSignalCandidate(
                puzzleAId: $row['puzzle_a_id'],
                puzzleBId: $row['puzzle_b_id'],
                matchingResults: (int) $row['matching_results'],
                matchingPeople: (int) $row['matching_people'],
                examplePlayerId: $row['example_player_id'],
                exampleSeconds: (int) $row['example_seconds'],
                exampleDay: new DateTimeImmutable($row['example_day']),
                puzzleAName: $row['puzzle_a_name'],
                puzzleBName: $row['puzzle_b_name'],
                puzzleAEan: $row['puzzle_a_ean'],
                puzzleACode: $row['puzzle_a_code'],
                puzzleBEan: $row['puzzle_b_ean'],
                puzzleBCode: $row['puzzle_b_code'],
                nameSimilarity: (float) $row['name_similarity'],
                nameContained: $row['name_contained'],
                sameBrand: $row['same_brand'] === true,
                bothApproved: $row['both_approved'],
                fewerResults: (int) $row['fewer_results'],
                moreResults: (int) $row['more_results'],
                addedSecondsApart: $row['added_seconds_apart'] === null ? null : (int) $row['added_seconds_apart'],
                daysFromNewerRecordToFirstMatch: $row['days_from_newer_record_to_first_match'] === null ? null : (int) $row['days_from_newer_record_to_first_match'],
            ),
            $rows,
        );
    }
}
