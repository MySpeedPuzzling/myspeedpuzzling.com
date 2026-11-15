<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;

/**
 * The player's own results as a hint for pairing (docs/features/data-import/pairing.md "Already yours"): which
 * waiting result rows of an import meet a result the player already has. One statement for the whole import.
 *
 * - `profile`: a result **tracked by the player** with the row's exact time on the row's day (or the day before or
 *   after - a result is stored in UTC, the file says the player's day), on a puzzle whose piece count agrees with
 *   the row's (one apart counts, an unknown count fits any). Only rows with an exact day and a time. Results the
 *   player is only a member of are left out on purpose: finding them needs the team snapshot, and one `OR` in this
 *   join is what turned the exact statement's first draft into a 45 s scan (README "Pairing with the catalogue").
 * - `earlier_import`: the row's `already_yours_solving_time_id` (DataImportRowRepository::markRowsOfEarlierImports())
 *   names a result that exists.
 *
 * A result counts whatever its state: one that waits for verification is the player's own result all the same, and
 * a result without a tracker (an event's imported result nobody claimed) is nobody's - the join on the player
 * leaves it out. A puzzle that may not be a candidate (GetImportPuzzleCandidates::sqlCandidate()) is never returned,
 * the player's own result on it or not.
 */
readonly final class GetImportRowsOnProfile
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array{rowId: string, importPuzzleId: string, how: string, solvingTimeId: string, puzzleId: string, pieces: int, manufacturerId: null|string, sameDay: bool, sameType: bool, seconds: int}>
     */
    public function hits(string $importId, string $playerId, DateTimeImmutable $now): array
    {
        $candidate = GetImportPuzzleCandidates::sqlCandidate('puzzle');

        $query = <<<SQL
WITH dated AS MATERIALIZED (
    SELECT
        data_import_row.id,
        data_import_row.import_puzzle_id,
        data_import_puzzle.pieces_count,
        CAST(data_import_row.parsed->>'date' AS DATE) AS day,
        CAST(data_import_row.parsed->>'seconds' AS INT) AS seconds,
        COALESCE(data_import_row.parsed->>'type', 'solo') AS puzzling_type
    FROM data_import_row
    INNER JOIN data_import_puzzle ON data_import_puzzle.id = data_import_row.import_puzzle_id
    WHERE data_import_row.import_id = :importId
        AND data_import_row.state = :waiting
        AND data_import_row.kind IN (:result, :both)
        AND data_import_row.already_yours_how IS NULL
        AND data_import_row.parsed->>'datePrecision' = 'day'
        AND data_import_row.parsed->>'date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
        AND data_import_row.parsed->>'seconds' ~ '^[0-9]{1,8}$'
)
SELECT
    dated.id AS row_id,
    dated.import_puzzle_id,
    'profile' AS how,
    puzzle_solving_time.id AS solving_time_id,
    puzzle.id AS puzzle_id,
    puzzle.pieces_count,
    puzzle.manufacturer_id,
    CAST(COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) AS DATE) = dated.day AS same_day,
    puzzle_solving_time.puzzling_type = dated.puzzling_type AS same_type,
    dated.seconds
FROM dated
INNER JOIN puzzle_solving_time
    ON puzzle_solving_time.player_id = :playerId
    AND puzzle_solving_time.seconds_to_solve = dated.seconds
    AND CAST(COALESCE(puzzle_solving_time.finished_at, puzzle_solving_time.tracked_at) AS DATE) BETWEEN dated.day - 1 AND dated.day + 1
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE (dated.pieces_count IS NULL OR ABS(puzzle.pieces_count - dated.pieces_count) <= 1)
    AND {$candidate}

UNION ALL

SELECT
    data_import_row.id,
    data_import_row.import_puzzle_id,
    'earlier_import',
    puzzle_solving_time.id,
    puzzle.id,
    puzzle.pieces_count,
    puzzle.manufacturer_id,
    TRUE,
    TRUE,
    COALESCE(puzzle_solving_time.seconds_to_solve, 0)
FROM data_import_row
INNER JOIN puzzle_solving_time ON puzzle_solving_time.id = data_import_row.already_yours_solving_time_id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE data_import_row.import_id = :importId
    AND data_import_row.import_puzzle_id IS NOT NULL
    AND data_import_row.already_yours_how = 'earlier_import'
    AND {$candidate}
ORDER BY 1, 4
SQL;

        /** @var list<array{row_id: string, import_puzzle_id: string, how: string, solving_time_id: string, puzzle_id: string, pieces_count: int, manufacturer_id: null|string, same_day: bool, same_type: bool, seconds: int}> $rows */
        $rows = $this->database->executeQuery($query, [
            'importId' => $importId,
            'playerId' => $playerId,
            'waiting' => DataImportRowState::Waiting->value,
            'result' => DataImportRowKind::Result->value,
            'both' => DataImportRowKind::Both->value,
            'now' => $now->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'rowId' => $row['row_id'],
            'importPuzzleId' => $row['import_puzzle_id'],
            'how' => $row['how'],
            'solvingTimeId' => $row['solving_time_id'],
            'puzzleId' => $row['puzzle_id'],
            'pieces' => $row['pieces_count'],
            'manufacturerId' => $row['manufacturer_id'],
            'sameDay' => $row['same_day'],
            'sameType' => $row['same_type'],
            'seconds' => $row['seconds'],
        ], $rows);
    }
}
