<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

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
     * @return list<array{id: string, position: int, title: string, mappingVersion: int, mapping: null|array<mixed>, options: null|array<mixed>, valueAnswers: null|array<mixed>, rowsTotal: int}>
     */
    public function sheetsBeingSetUp(string $importId): array
    {
        if (Uuid::isValid($importId) === false) {
            return [];
        }

        /** @var list<array{id: string, position: int, title: string, mapping_version: int, mapping: null|string, options: null|string, value_answers: null|string, rows_total: int}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT sheet.id, sheet.position, sheet.title, sheet.mapping_version, sheet.mapping, sheet.options, sheet.value_answers, sheet.rows_total
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
     * The sheet's rows from its top, a page at a time - at most $limit of them.
     *
     * @return \Generator<int, array<int, array<string, mixed>>> row number => cells by column
     */
    public function rows(string $sheetId, int $limit): \Generator
    {
        $after = 0;
        $given = 0;

        while ($given < $limit) {
            $page = $this->page($sheetId, $after, min(DataImportLimits::ROWS_PER_BATCH, $limit - $given));

            foreach ($page as $row) {
                yield $row['number'] => $row['cells'];
                $after = $row['number'];
                $given++;
            }

            if (count($page) < DataImportLimits::ROWS_PER_BATCH) {
                return;
            }
        }
    }

    /**
     * The next rows of a sheet after a row number, as they are stored.
     *
     * @return list<array{id: string, number: int, cells: array<int, array<string, mixed>>}>
     */
    public function page(string $sheetId, int $afterRowNumber, int $limit): array
    {
        /** @var list<array{id: string, row_number: int, cells: null|string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT id, row_number, cells
FROM data_import_row
WHERE sheet_id = :sheetId
    AND row_number > :after
ORDER BY row_number
LIMIT :limit
SQL, ['sheetId' => $sheetId, 'after' => $afterRowNumber, 'limit' => $limit])->fetchAllAssociative();

        return array_map(static function (array $row): array {
            /** @var array<int, array<string, mixed>> $cells */
            $cells = self::json($row['cells']) ?? [];

            return ['id' => $row['id'], 'number' => $row['row_number'], 'cells' => $cells];
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
