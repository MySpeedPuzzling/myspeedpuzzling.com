<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\ImportRowDetail;
use SpeedPuzzling\Web\Results\ImportRowsToFixGroup;
use SpeedPuzzling\Web\Results\ImportSheetRow;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * The rows of the signed-in player's own import that need a look (docs/features/data-import/pairing-screens.md "To
 * fix", "The row sheet") - grouped by what is unclear and the value as the sheet has it, and one row with its cells.
 * Every statement names the player; a group is named by a key (ImportRowAnswers::problemKey()), never by its value.
 *
 * A row counts while it waits, is a puzzle, was read for its sheet's current mapping and carries a problem that no
 * question of its sheet decides (those wait for the sheet's answer, not for the row's).
 */
readonly final class GetImportRowsToFix
{
    private const string ROWS = <<<SQL
FROM data_import_row stored
INNER JOIN data_import_sheet sheet ON sheet.id = stored.sheet_id
INNER JOIN data_import ON data_import.id = stored.import_id
CROSS JOIN LATERAL jsonb_array_elements(stored.problems) AS problem(item)
WHERE stored.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
    AND stored.state = 'waiting'
    AND stored.kind <> 'not_a_puzzle'
    AND stored.parsed_version = sheet.mapping_version
    AND stored.problems IS NOT NULL
    AND COALESCE(problem.item ->> 'waiting', 'false') <> 'true'
SQL;

    private const string KEY = "md5(COALESCE(problem.item ->> 'field', '') || ':' || (problem.item ->> 'code') || ':' || COALESCE(problem.item ->> 'value', ''))";

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * A page of groups, the largest first - or the one group with a key. One statement.
     *
     * @return array{groups: list<ImportRowsToFixGroup>, total: int}
     */
    public function groups(string $importId, string $playerId, int $limit, int $offset, null|string $onlyKey = null): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return ['groups' => [], 'total' => 0];
        }

        $rows = self::ROWS;
        $key = self::KEY;
        $only = $onlyKey === null ? '' : "HAVING {$key} = :key";

        /** @var list<array{field: null|string, code: string, value: null|string, key: string, rows: int, sample_row_id: string, seconds_min: null|int, seconds_max: null|int, date_min: null|string, date_max: null|string, total: int}> $found */
        $found = $this->database->executeQuery(<<<SQL
SELECT
    problem.item ->> 'field' AS field,
    problem.item ->> 'code' AS code,
    problem.item ->> 'value' AS value,
    {$key} AS key,
    COUNT(*) AS rows,
    MIN(CAST(stored.id AS TEXT)) AS sample_row_id,
    MIN(CAST(stored.parsed ->> 'seconds' AS INT)) AS seconds_min,
    MAX(CAST(stored.parsed ->> 'seconds' AS INT)) AS seconds_max,
    MIN(CASE WHEN stored.parsed ->> 'datePrecision' = 'day' THEN stored.parsed ->> 'date' ELSE '' END) AS date_min,
    MAX(CASE WHEN stored.parsed ->> 'datePrecision' = 'day' THEN stored.parsed ->> 'date' ELSE '' END) AS date_max,
    COUNT(*) OVER () AS total
{$rows}
GROUP BY problem.item ->> 'field', problem.item ->> 'code', problem.item ->> 'value'
{$only}
ORDER BY COUNT(*) DESC, problem.item ->> 'code', problem.item ->> 'value', problem.item ->> 'field'
LIMIT :limit OFFSET :offset
SQL, array_filter([
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
            'limit' => $limit,
            'offset' => $offset,
            'key' => $onlyKey,
        ], static fn (mixed $value): bool => $value !== null))->fetchAllAssociative();

        $groups = [];

        foreach ($found as $group) {
            $field = $group['field'] ?? '';
            $proposal = match (true) {
                // Every row of the group reads the same: offered, for the player's yes
                $field === 'time' && $group['seconds_min'] !== null && $group['seconds_min'] === $group['seconds_max'] && $group['seconds_min'] > 0
                    => sprintf('%d:%02d:%02d', intdiv($group['seconds_min'], 3600), intdiv($group['seconds_min'] % 3600, 60), $group['seconds_min'] % 60),
                $field === 'solved_on' && $group['date_min'] !== null && $group['date_min'] !== '' && $group['date_min'] === $group['date_max'] => $group['date_min'],
                default => null,
            };

            $groups[] = new ImportRowsToFixGroup(
                key: $group['key'],
                field: $field,
                code: $group['code'],
                value: $group['value'] ?? '',
                rows: $group['rows'],
                sampleRowId: $group['sample_row_id'],
                // One answer only for rows that share what the sheet says: a group without a value (rows without a
                // name, a status that carries no readable date) is not one thing - each row is answered on its own
                input: ($group['value'] ?? '') === '' ? null : ImportRowAnswers::INPUTS[$field] ?? null,
                proposal: $proposal,
            );
        }

        return ['groups' => $groups, 'total' => $found === [] ? 0 : $found[0]['total']];
    }

    /**
     * The rows of one group, at most $limit of them. One statement.
     *
     * @return list<string> ids
     */
    public function rowIdsOfGroup(string $importId, string $playerId, string $key, int $limit): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return [];
        }

        $rows = self::ROWS;
        $keyOf = self::KEY;

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
SELECT DISTINCT stored.id
{$rows}
    AND {$keyOf} = :key
ORDER BY stored.id
LIMIT :limit
SQL, ['importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value, 'key' => $key, 'limit' => $limit])->fetchFirstColumn();

        return $ids;
    }

    /**
     * The row that needs a look after this one in the file's order - or, past the last, the first one. Null: no
     * other row needs a look. One statement.
     */
    public function nextRowId(string $importId, string $playerId, string $afterRowId): null|string
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false || Uuid::isValid($afterRowId) === false) {
            return null;
        }

        $rows = self::ROWS;
        $id = $this->database->executeQuery(<<<SQL
SELECT CAST(stored.id AS TEXT)
{$rows}
    AND stored.id <> :after
ORDER BY
    CASE WHEN (sheet.position, stored.row_number) > (
        SELECT here_sheet.position, here.row_number
        FROM data_import_row here
        INNER JOIN data_import_sheet here_sheet ON here_sheet.id = here.sheet_id
        WHERE here.id = :after
    ) THEN 0 ELSE 1 END,
    sheet.position,
    stored.row_number
LIMIT 1
SQL, ['importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value, 'after' => $afterRowId])->fetchOne();

        return is_string($id) ? $id : null;
    }

    /**
     * The rows the player left out themselves, in the file's order - to take them in again. One statement.
     *
     * @return array{rows: list<ImportSheetRow>, total: int}
     */
    public function leftOutByPlayer(string $importId, string $playerId, int $limit): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return ['rows' => [], 'total' => 0];
        }

        /** @var list<array{id: string, row_number: int, kind: null|string, parsed: null|string, problems: null|string, title: string, total: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT stored.id, stored.row_number, stored.kind, stored.parsed, stored.problems, sheet.title, COUNT(*) OVER () AS total
FROM data_import_row stored
INNER JOIN data_import_sheet sheet ON sheet.id = stored.sheet_id
INNER JOIN data_import ON data_import.id = stored.import_id
WHERE stored.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
    AND stored.state = 'left_out'
    AND stored.parsed ->> 'leftOut' = 'by_player'
ORDER BY sheet.position, stored.row_number
LIMIT :limit
SQL, ['importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value, 'limit' => $limit])->fetchAllAssociative();

        return ['rows' => array_map(GetImportPuzzleScreen::row(...), $rows), 'total' => $rows === [] ? 0 : $rows[0]['total']];
    }

    /**
     * One row with its cells and its sheet's mapping. One statement.
     *
     * @throws DataImportNotFound
     */
    public function row(string $importId, string $playerId, string $rowId): ImportRowDetail
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false || Uuid::isValid($rowId) === false) {
            throw new DataImportNotFound();
        }

        /** @var false|array{id: string, row_number: int, kind: null|string, parsed: null|string, problems: null|string, title: string, sheet_id: string, state: string, cells: null|string, answers: null|string, mapping: null|string, parsed_version: null|int, mapping_version: int, sheet_parsed_version: null|int, set_up_failure: null|string, solving_time_id: null|string, status: string, cells_wiped: bool} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    stored.id,
    stored.row_number,
    stored.kind,
    stored.parsed,
    stored.problems,
    sheet.title,
    sheet.id AS sheet_id,
    stored.state,
    stored.cells,
    stored.answers,
    sheet.mapping,
    stored.parsed_version,
    sheet.mapping_version,
    sheet.parsed_version AS sheet_parsed_version,
    sheet.set_up_failure,
    stored.solving_time_id,
    data_import.status,
    data_import.cells_wiped_at IS NOT NULL AS cells_wiped
FROM data_import_row stored
INNER JOIN data_import_sheet sheet ON sheet.id = stored.sheet_id
INNER JOIN data_import ON data_import.id = stored.import_id
WHERE stored.id = :rowId
    AND stored.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
SQL, ['rowId' => $rowId, 'importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        $answers = self::json($row['answers']) ?? [];
        /** @var array<int, array<string, mixed>> $cells */
        $cells = self::json($row['cells']) ?? [];
        $beingSetUp = $row['status'] === DataImportStatus::SettingUp->value;

        return new ImportRowDetail(
            row: GetImportPuzzleScreen::row($row),
            rowId: $row['id'],
            sheetId: $row['sheet_id'],
            sheetTitle: $row['title'],
            state: $row['state'],
            cells: $cells,
            answers: $answers,
            mapping: self::json($row['mapping']),
            stamp: DataImportRow::stampOf($row['parsed_version'], self::json($row['answers'])),
            canBeAnswered: $beingSetUp
                && $row['cells_wiped'] === false
                && $row['mapping'] !== null
                && $row['set_up_failure'] === null
                && $row['sheet_parsed_version'] === $row['mapping_version']
                && $row['parsed_version'] === $row['mapping_version']
                && $row['solving_time_id'] === null
                && in_array($row['state'], ['waiting', 'left_out'], true),
            beingSetUp: $beingSetUp,
        );
    }

    /**
     * @return null|array<mixed>
     */
    private static function json(null|string $stored): null|array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }
}
