<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * An import's stored sheets and rows as the background steps read them (docs/features/data-import/columns.md):
 * a sample for recognising, pages of raw cells for settling and parsing. For the import's own jobs and handlers
 * only - **never for a page: no player is checked here**. Nothing is loaded whole: a sheet may hold 50,000 rows.
 */
readonly final class GetImportSheetRows
{
    /** Excel tables beyond the sample whose header rows are fetched too */
    private const int TABLES_LOOKED_AT = 40;
    private const int ROWS_UNDER_A_TABLE_HEADER = 60;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The sheets of an import that was read and is being set up, in the file's order - none for any other import.
     *
     * @return list<array{id: string, position: int, title: string, mappingVersion: int, mapping: null|array<mixed>, options: null|array<mixed>, valueAnswers: null|array<mixed>, rowsTotal: int, confirmedByPlayer: bool}>
     */
    public function sheetsBeingSetUp(string $importId): array
    {
        if (Uuid::isValid($importId) === false) {
            return [];
        }

        /** @var list<array{id: string, position: int, title: string, mapping_version: int, mapping: null|string, options: null|string, value_answers: null|string, rows_total: int, confirmed_by_player: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT sheet.id, sheet.position, sheet.title, sheet.mapping_version, sheet.mapping, sheet.options, sheet.value_answers, sheet.rows_total, sheet.confirmed_by_player
FROM data_import_sheet sheet
INNER JOIN data_import ON data_import.id = sheet.import_id
WHERE sheet.import_id = :importId
    AND data_import.status = :settingUp
ORDER BY sheet.position
SQL, ['importId' => $importId, 'settingUp' => DataImportStatus::SettingUp->value])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'position' => $row['position'],
            'title' => $row['title'],
            'mappingVersion' => $row['mapping_version'],
            'mapping' => self::json($row['mapping']),
            'options' => self::json($row['options']),
            'valueAnswers' => self::json($row['value_answers']),
            'rowsTotal' => $row['rows_total'],
            'confirmedByPlayer' => $row['confirmed_by_player'],
        ], $rows);
    }

    /**
     * What the recognisers see of a sheet: its first rows, and the rows around the header of every Excel table the
     * file says it has further down.
     */
    public function sample(string $sheetId): SheetSample
    {
        /** @var false|array{title: string, hints: null|string, rows_total: int} $sheet */
        $sheet = $this->database->executeQuery(
            'SELECT title, hints, rows_total FROM data_import_sheet WHERE id = :sheetId',
            ['sheetId' => $sheetId],
        )->fetchAssociative();

        if ($sheet === false) {
            return new SheetSample('', []);
        }

        $hints = self::json($sheet['hints']);
        $rows = [];

        foreach ($this->page($sheetId, 0, DataImportLimits::SAMPLE_ROWS) as $row) {
            $rows[$row['number']] = $row['cells'];
        }

        $last = $rows === [] ? 0 : (int) array_key_last($rows);
        $tables = is_array($hints['tables'] ?? null) ? array_slice($hints['tables'], 0, self::TABLES_LOOKED_AT) : [];

        foreach ($tables as $table) {
            $headerRow = is_array($table) && is_int($table['headerRow'] ?? null) ? $table['headerRow'] : null;

            if ($headerRow !== null && $headerRow > $last) {
                foreach ($this->page($sheetId, $headerRow - 1, self::ROWS_UNDER_A_TABLE_HEADER) as $row) {
                    $rows[$row['number']] = $row['cells'];
                }
            }
        }

        ksort($rows);

        return new SheetSample($sheet['title'], $rows, $hints, $sheet['rows_total']);
    }

    /**
     * A sheet's rows in their order, a page at a time - from a row to a row (null = the sheet's end), at most
     * $limit of them. What a table's questions are settled from reads only the table's rows.
     *
     * @return \Generator<int, array<int, array<string, mixed>>> row number => cells by column
     */
    public function rows(string $sheetId, int $limit, int $fromRow = 1, null|int $throughRow = null): \Generator
    {
        $after = $fromRow - 1;
        $given = 0;

        while ($given < $limit) {
            $page = $this->page($sheetId, $after, min(DataImportLimits::ROWS_PER_BATCH, $limit - $given), $throughRow);

            if ($page === []) {
                return;
            }

            foreach ($page as $row) {
                yield $row['number'] => $row['cells'];
                $after = $row['number'];
                $given++;
            }
        }
    }

    /**
     * The next rows of a sheet after a row number, as they are stored - at most $limit rows **and** about $maxBytes
     * of cells (the row that crosses the bound is the page's last; a page always holds a row while one is left):
     * a row may hold megabytes, and five hundred of them must never be in memory at once.
     *
     * @return list<array{id: string, number: int, cells: array<int, array<string, mixed>>, answers: array<mixed>}>
     */
    public function page(string $sheetId, int $afterRowNumber, int $limit, null|int $throughRow = null, int $maxBytes = DataImportLimits::BYTES_PER_BATCH): array
    {
        /** @var list<array{id: string, row_number: int, cells: null|string, answers: null|string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT sized.id, sized.row_number, stored.cells, stored.answers
FROM (
    SELECT next.id, next.row_number, COALESCE(SUM(next.bytes) OVER (ORDER BY next.row_number ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) AS bytes_before
    FROM (
        SELECT id, row_number, COALESCE(octet_length(cells::text), 0) AS bytes
        FROM data_import_row
        WHERE sheet_id = :sheetId
            AND row_number > :after
            AND row_number <= :through
        ORDER BY row_number
        LIMIT :limit
    ) next
) sized
INNER JOIN data_import_row stored ON stored.id = sized.id
WHERE sized.bytes_before < :maxBytes
ORDER BY sized.row_number
SQL, ['sheetId' => $sheetId, 'after' => $afterRowNumber, 'through' => $throughRow ?? 2_147_483_647, 'limit' => $limit, 'maxBytes' => $maxBytes])->fetchAllAssociative();

        return array_map(static function (array $row): array {
            /** @var array<int, array<string, mixed>> $cells */
            $cells = self::json($row['cells']) ?? [];

            // What the player said about the row itself (AnswerImportRows) is read with it: every parse honours it
            return ['id' => $row['id'], 'number' => $row['row_number'], 'cells' => $cells, 'answers' => self::json($row['answers']) ?? []];
        }, $rows);
    }

    /**
     * One column's cells of a sheet, in the sheet's order, streamed - nothing else of the rows (what a puzzle list's
     * names are read from when only a few of them are needed).
     *
     * @return \Generator<int, array<string, mixed>> row number => the cell
     */
    public function cellsOfColumn(string $sheetId, int $column): \Generator
    {
        $rows = $this->database->executeQuery(
            'SELECT row_number, cells -> CAST(:column AS TEXT) AS cell FROM data_import_row WHERE sheet_id = :sheetId AND jsonb_exists(cells, CAST(:column AS TEXT)) ORDER BY row_number',
            ['sheetId' => $sheetId, 'column' => (string) $column],
        );

        foreach ($rows->iterateAssociative() as $row) {
            $cell = is_string($row['cell']) ? self::json($row['cell']) : null;

            if ($cell !== null && is_int($row['row_number'])) {
                /** @var array<string, mixed> $cell */
                yield $row['row_number'] => $cell;
            }
        }
    }

    /**
     * Some rows of a sheet by their numbers, as they are stored.
     *
     * @param list<int> $rowNumbers
     *
     * @return array<int, array<int, array<string, mixed>>> row number => cells by column
     */
    public function rowsByNumbers(string $sheetId, array $rowNumbers): array
    {
        if ($rowNumbers === []) {
            return [];
        }

        /** @var list<array{row_number: int, cells: null|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT row_number, cells FROM data_import_row WHERE sheet_id = :sheetId AND row_number IN (:numbers) ORDER BY row_number',
            ['sheetId' => $sheetId, 'numbers' => $rowNumbers],
            ['numbers' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $byNumber = [];

        foreach ($rows as $row) {
            /** @var array<int, array<string, mixed>> $cells */
            $cells = self::json($row['cells']) ?? [];
            $byNumber[$row['row_number']] = $cells;
        }

        return $byNumber;
    }

    /**
     * The keys several rows of a sheet are equal by, in the keys' order after one of them - a page of whole
     * groups, never a cut one.
     *
     * @return list<string>
     */
    public function sharedEqualKeys(string $sheetId, string $afterKey, int $limit): array
    {
        /** @var list<string> $keys */
        $keys = $this->database->executeQuery(<<<SQL
SELECT equal_key
FROM data_import_row
WHERE sheet_id = :sheetId
    AND equal_key > :after
GROUP BY equal_key
HAVING COUNT(*) > 1
ORDER BY equal_key
LIMIT :limit
SQL, ['sheetId' => $sheetId, 'after' => $afterKey, 'limit' => $limit])->fetchFirstColumn();

        return $keys;
    }

    /**
     * Every row of a sheet that is equal to another by one of these keys - without its cells, with how much they
     * weigh.
     *
     * @param list<string> $equalKeys
     *
     * @return list<array{id: string, equalKey: string, rowNumber: int, kind: null|string, puzzleKey: null|string, parsed: array<mixed>, bytes: int}>
     */
    public function rowsEqualBy(string $sheetId, array $equalKeys): array
    {
        if ($equalKeys === []) {
            return [];
        }

        /** @var list<array{id: string, equal_key: string, row_number: int, kind: null|string, puzzle_key: null|string, parsed: null|string, bytes: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT id, equal_key, row_number, kind, puzzle_key, parsed, COALESCE(octet_length(cells::text), 0) AS bytes
FROM data_import_row
WHERE sheet_id = :sheetId
    AND equal_key IN (:keys)
    AND state IN ('waiting', 'left_out')
ORDER BY equal_key, row_number
SQL, ['sheetId' => $sheetId, 'keys' => $equalKeys], ['keys' => ArrayParameterType::STRING])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'equalKey' => $row['equal_key'],
            'rowNumber' => $row['row_number'],
            'kind' => $row['kind'],
            'puzzleKey' => $row['puzzle_key'],
            'parsed' => self::json($row['parsed']) ?? [],
            'bytes' => $row['bytes'],
        ], $rows);
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
