<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\ImportSheetDetail;
use SpeedPuzzling\Web\Results\ImportSheetRow;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * One sheet of the signed-in player's import as the "Sheets and columns" screen shows it
 * (docs/features/data-import/columns-screen.md): its mapping and state, a few of its first rows for the columns'
 * sample values, the first rows as they were understood, and the rows that need a look - a page at a time.
 *
 * For the player's own pages only: every statement names the player who is signed in, never an id from a URL alone -
 * another player's import or sheet is "not found". Nothing is loaded whole: a sheet may hold 50,000 rows.
 */
readonly final class GetImportSheetScreen
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws DataImportNotFound no such sheet of such an import, a discarded import, or another player's
     */
    public function sheet(string $importId, string $sheetId, string $playerId): ImportSheetDetail
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($sheetId) === false || Uuid::isValid($playerId) === false) {
            throw new DataImportNotFound();
        }

        /** @var false|array{id: string, title: string, position: int, hidden: bool, rows_total: int, kind: null|string, mapping_version: int, parsed_version: null|int, mapping: null|string, options: null|string, rows_understood: int, rows_need_look: int, rows_left_out: int, set_up_failure: null|string, status: string, lookup_title: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    sheet.id,
    sheet.title,
    sheet.position,
    sheet.hidden,
    sheet.rows_total,
    sheet.kind,
    sheet.mapping_version,
    sheet.parsed_version,
    sheet.mapping,
    sheet.options,
    sheet.rows_understood,
    sheet.rows_need_look,
    sheet.rows_left_out,
    sheet.set_up_failure,
    data_import.status,
    lookup.title AS lookup_title
FROM data_import_sheet sheet
INNER JOIN data_import ON data_import.id = sheet.import_id
LEFT JOIN data_import_sheet lookup
    ON lookup.import_id = sheet.import_id
    AND lookup.id::text = sheet.options ->> 'lookupSheetId'
WHERE sheet.id = :sheetId
    AND sheet.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
SQL, [
            'sheetId' => $sheetId,
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        return new ImportSheetDetail(
            sheetId: $row['id'],
            title: $row['title'],
            position: $row['position'],
            hidden: $row['hidden'],
            rows: $row['rows_total'],
            kind: $row['kind'] === null ? null : DataImportSheetKind::tryFrom($row['kind']),
            mappingVersion: $row['mapping_version'],
            parsed: $row['mapping_version'] > 0 && $row['parsed_version'] === $row['mapping_version'],
            mapping: self::json($row['mapping']),
            options: self::json($row['options']),
            rowsUnderstood: $row['rows_understood'],
            rowsNeedLook: $row['rows_need_look'],
            rowsLeftOut: $row['rows_left_out'],
            failure: $row['set_up_failure'],
            beingSetUp: $row['status'] === DataImportStatus::SettingUp->value,
            lookupSheetTitle: $row['lookup_title'],
        );
    }

    /**
     * The cells of a sheet's first rows from a row on - what the columns' sample values are taken from.
     *
     * @return list<array<int, array<string, mixed>>> cells by column, a row after the other
     */
    public function firstCells(string $importId, string $sheetId, string $playerId, int $fromRow, int $limit): array
    {
        /** @var list<null|string> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT stored.cells
FROM data_import_row stored
INNER JOIN data_import ON data_import.id = stored.import_id
WHERE stored.sheet_id = :sheetId
    AND stored.import_id = :importId
    AND data_import.player_id = :playerId
    AND stored.row_number >= :fromRow
ORDER BY stored.row_number
LIMIT :limit
SQL, ['sheetId' => $sheetId, 'importId' => $importId, 'playerId' => $playerId, 'fromRow' => $fromRow, 'limit' => $limit])->fetchFirstColumn();

        $cells = [];

        foreach ($rows as $row) {
            /** @var array<int, array<string, mixed>> $decoded */
            $decoded = self::json($row) ?? [];
            $cells[] = $decoded;
        }

        return $cells;
    }

    /**
     * Rows of the sheet that are puzzles, as they were understood for the sheet's current mapping, in the sheet's
     * order - all of them, or only those that need a look.
     *
     * @return list<ImportSheetRow>
     */
    public function understoodRows(string $importId, string $sheetId, string $playerId, bool $onlyNeedingALook, int $limit, int $offset = 0): array
    {
        $needALook = $onlyNeedingALook ? 'AND stored.problems IS NOT NULL' : '';

        /** @var list<array{row_number: int, kind: string, parsed: null|string, problems: null|string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT stored.row_number, stored.kind, stored.parsed, stored.problems
FROM data_import_row stored
INNER JOIN data_import_sheet sheet ON sheet.id = stored.sheet_id
INNER JOIN data_import ON data_import.id = stored.import_id
WHERE stored.sheet_id = :sheetId
    AND stored.import_id = :importId
    AND data_import.player_id = :playerId
    AND stored.parsed_version = sheet.mapping_version
    AND stored.state = 'waiting'
    AND stored.kind <> 'not_a_puzzle'
    {$needALook}
ORDER BY stored.row_number
LIMIT :limit OFFSET :offset
SQL, ['sheetId' => $sheetId, 'importId' => $importId, 'playerId' => $playerId, 'limit' => $limit, 'offset' => $offset])->fetchAllAssociative();

        return array_map(static function (array $row): ImportSheetRow {
            /** @var array<string, mixed> $parsed */
            $parsed = self::json($row['parsed']) ?? [];
            $problems = [];

            foreach (self::json($row['problems']) ?? [] as $problem) {
                if (is_array($problem) && is_string($problem['code'] ?? null)) {
                    $problems[] = [
                        'field' => is_string($problem['field'] ?? null) ? $problem['field'] : '',
                        'code' => $problem['code'],
                        'value' => is_string($problem['value'] ?? null) ? $problem['value'] : '',
                    ];
                }
            }

            return new ImportSheetRow($row['row_number'], $row['kind'], $parsed, $problems);
        }, $rows);
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
