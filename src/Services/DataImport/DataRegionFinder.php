<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Value\DataImport\DataRegion;
use SpeedPuzzling\Web\Value\DataImport\DataRegionSource;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;

/**
 * Finds where a sheet's data is (docs/features/data-import/README.md "Where the data is", columns.md): the header
 * row and the table(s) under it. In this order:
 *
 * 1. **Excel tables** ("Format as table") say it exactly - header row, range, totals rows. Twenty small tables side
 *    by side are twenty regions.
 * 2. Without one, the header is the row above a **frozen pane**,
 * 3. else the first row whose cells are **column names we know** (HeaderMatcher, or a known export's header set),
 * 4. else the first row of **texts over rows of other types** - which skips a title line and a dashboard of formulas
 *    above the table,
 * 5. else there is no header: the columns are "Column N".
 *
 * Under a header found by rows, a column that is empty in the header and in every row below it may be the border
 * between two tables standing side by side - or a spacer inside one (tablesOf()). A table runs to the sheet's end: what stands under the data (totals, notes) is left
 * out row by row (PuzzleRowFilter), not by a guess where the table ends.
 *
 * The largest table comes first. A table wider than DataImportLimits::MAX_REGION_COLUMNS is cut on its right.
 */
final readonly class DataRegionFinder
{
    /** Rows from the top a header is looked for in */
    private const int HEADER_WITHIN_ROWS = 60;
    /** Rows under a header candidate that say what the table's columns hold */
    private const int ROWS_UNDER_A_HEADER = 25;
    /** A header is kept this long at most (a table's header row may hold anything) */
    private const int MAX_HEADER_LENGTH = 200;
    /** Tables kept of a sheet */
    private const int MAX_REGIONS = 200;

    public function __construct(
        private HeaderMatcher $headerMatcher,
    ) {
    }

    /**
     * @return list<DataRegion> the largest first - empty for a sheet without values
     */
    public function find(SheetSample $sample): array
    {
        $regions = $this->tables($sample);

        if ($regions === []) {
            $regions = $this->byRows($sample);
        }

        usort($regions, static fn (DataRegion $a, DataRegion $b): int => [$b->rows * $b->columns(), $a->firstColumn] <=> [$a->rows * $a->columns(), $b->firstColumn]);

        return array_slice($regions, 0, self::MAX_REGIONS);
    }

    /**
     * The header texts of a table, by column - '' where the header row has none.
     *
     * @return array<int, string>
     */
    public static function headers(SheetSample $sample, DataRegion $region): array
    {
        $headers = [];
        $row = $region->headerRow === null ? [] : $sample->rows[$region->headerRow] ?? [];

        for ($column = $region->firstColumn; $column <= $region->lastColumn; $column++) {
            $headers[$column] = mb_substr(trim(StoredCells::shown($row[$column] ?? null)), 0, self::MAX_HEADER_LENGTH);
        }

        return $headers;
    }

    /**
     * @return list<DataRegion>
     */
    private function tables(SheetSample $sample): array
    {
        $tables = $sample->hints['tables'] ?? null;
        $regions = [];

        foreach (is_array($tables) ? $tables : [] as $table) {
            if (is_array($table) === false) {
                continue;
            }

            $range = is_string($table['range'] ?? null) ? CellRange::fromA1($table['range']) : null;

            if ($range === null || $range->firstColumn > DataImportLimits::MAX_COLUMNS) {
                continue;
            }

            $headerRow = is_int($table['headerRow'] ?? null) ? $table['headerRow'] : null;
            $totalsRows = is_int($table['totalsRows'] ?? null) ? max(0, $table['totalsRows']) : 0;
            $firstRow = $headerRow === null ? $range->firstRow : $headerRow + 1;
            $lastRow = max($firstRow, $range->lastRow - $totalsRows);
            $lastColumn = min($range->lastColumn, $range->firstColumn + DataImportLimits::MAX_REGION_COLUMNS - 1, DataImportLimits::MAX_COLUMNS);

            $regions[] = new DataRegion(
                $range->firstColumn,
                $lastColumn,
                $headerRow,
                $firstRow,
                $lastRow,
                DataRegionSource::Table,
                is_string($table['name'] ?? null) ? mb_substr($table['name'], 0, self::MAX_HEADER_LENGTH) : null,
                max(0, $range->lastRow - $totalsRows - $firstRow + 1),
                $lastColumn < $range->lastColumn,
            );
        }

        return $regions;
    }

    /**
     * @return list<DataRegion>
     */
    private function byRows(SheetSample $sample): array
    {
        if ($sample->rows === []) {
            return [];
        }

        [$headerRow, $source] = $this->headerRow($sample);
        $below = [];

        foreach ($sample->rows as $number => $cells) {
            if ($headerRow === null || $number > $headerRow) {
                $below[$number] = $cells;
            }
        }

        $header = $headerRow === null ? [] : $sample->rows[$headerRow];
        $used = [];

        foreach ([$header, ...$below] as $cells) {
            foreach ($cells as $column => $cell) {
                if (StoredCells::isBlank($cell) === false) {
                    $used[$column] = true;
                }
            }
        }

        ksort($used);
        $regions = [];
        $firstRow = $headerRow === null ? (int) array_key_first($sample->rows) : $headerRow + 1;

        foreach ($this->tablesOf(self::runs(array_keys($used)), $header, $headerRow !== null) as [$first, $last]) {
            $rows = count(array_filter($below, static function (array $cells) use ($first, $last): bool {
                foreach ($cells as $column => $cell) {
                    if ($column >= $first && $column <= $last && StoredCells::isBlank($cell) === false) {
                        return true;
                    }
                }

                return false;
            }));

            $cutAt = min($last, $first + DataImportLimits::MAX_REGION_COLUMNS - 1);
            $regions[] = new DataRegion(
                $first,
                $cutAt,
                $headerRow,
                $firstRow,
                null,
                $source,
                null,
                // What the sample does not show is taken to go on as it began
                $sample->isCut() ? $rows + $sample->rowsTotal - count($sample->rows) : $rows,
                $cutAt < $last,
            );
        }

        return $regions;
    }

    /**
     * Runs of used columns, joined into tables. An empty column is the border between two tables only when the block
     * right of it starts a table of its own: it repeats a header of the table left of it (twenty small tables with
     * the same headers), or it has a puzzle-name column and so has the table left of it (two piles side by side).
     * Otherwise it is a spacer inside one table. A block without any header beside a table is somebody's notes - not
     * a table, and not part of one. Without a header row the sheet is one table.
     *
     * @param list<array{int, int}> $runs
     * @param array<int, array<string, mixed>> $header
     *
     * @return list<array{int, int}>
     */
    private function tablesOf(array $runs, array $header, bool $hasHeaderRow): array
    {
        if ($runs === []) {
            return [];
        }

        if ($hasHeaderRow === false) {
            return [[$runs[0][0], $runs[array_key_last($runs)][1]]];
        }

        $tables = [];
        $current = null;
        $words = [];
        $hasName = false;

        foreach ($runs as [$first, $last]) {
            $runWords = [];
            $runHasName = false;

            for ($column = $first; $column <= $last; $column++) {
                $text = trim(StoredCells::shown($header[$column] ?? null));

                if ($text === '') {
                    continue;
                }

                $runWords[] = HeaderMatcher::words($text);

                foreach ($this->headerMatcher->guess($text) as $guess) {
                    $runHasName = $runHasName || $guess->field === ImportField::PuzzleName;
                }
            }

            if ($runWords === []) {
                continue;
            }

            $startsATable = $current === null || array_intersect($runWords, $words) !== [] || ($runHasName && $hasName);

            if ($startsATable) {
                if ($current !== null) {
                    $tables[] = $current;
                }

                $current = [$first, $last];
                $words = $runWords;
                $hasName = $runHasName;
            } else {
                $current = [$current[0], $last];
                $words = [...$words, ...$runWords];
                $hasName = $hasName || $runHasName;
            }
        }

        if ($current !== null) {
            $tables[] = $current;
        }

        return $tables;
    }

    /**
     * @return array{null|int, DataRegionSource}
     */
    private function headerRow(SheetSample $sample): array
    {
        $numbers = array_slice(array_keys($sample->rows), 0, self::HEADER_WITHIN_ROWS);

        $frozen = $sample->hints['frozenPane'] ?? null;
        $aboveThePane = is_array($frozen) && is_int($frozen['row'] ?? null) ? $frozen['row'] - 1 : 0;

        if ($aboveThePane >= 1 && isset($sample->rows[$aboveThePane]) && $this->texts($sample->rows[$aboveThePane]) !== []) {
            return [$aboveThePane, DataRegionSource::FrozenPane];
        }

        foreach ($numbers as $number) {
            $texts = $this->texts($sample->rows[$number]);

            if (KnownExportLayout::matching($texts) !== null) {
                return [$number, DataRegionSource::HeaderWords];
            }

            $known = count(array_filter($texts, fn (string $text): bool => $this->headerMatcher->guess($text) !== []));

            if ($known >= 2 && $known * 2 >= count($texts) && $this->allTexts($sample->rows[$number])) {
                return [$number, DataRegionSource::HeaderWords];
            }
        }

        foreach ($numbers as $position => $number) {
            if ($this->isHeaderByTypes($sample, $number, array_slice(array_keys($sample->rows), $position + 1, self::ROWS_UNDER_A_HEADER))) {
                return [$number, DataRegionSource::Types];
            }
        }

        return [null, DataRegionSource::NoHeader];
    }

    /**
     * A row of nothing but (typed, short) texts, as wide as the rows under it, over rows that hold something else
     * than text somewhere - what a header looks like when none of its words is known.
     *
     * @param list<int> $under
     */
    private function isHeaderByTypes(SheetSample $sample, int $number, array $under): bool
    {
        $row = $sample->rows[$number];
        $texts = $this->texts($row);

        if (count($texts) < 2 || $this->allTexts($row) === false || count(array_unique($texts)) < count($texts) || $under === []) {
            return false;
        }

        $widths = [];
        $typed = false;

        foreach ($under as $below) {
            $filled = 0;

            foreach ($sample->rows[$below] as $column => $cell) {
                if (StoredCells::isBlank($cell)) {
                    continue;
                }

                $filled++;
                $typed = $typed || (isset($row[$column]) && StoredCells::kind($cell) !== 'text');
            }

            $widths[] = $filled;
        }

        sort($widths);
        $usual = $widths[intdiv(count($widths), 2)];

        return $typed && count($texts) * 2 >= $usual;
    }

    /**
     * @param array<int, array<string, mixed>> $row
     *
     * @return array<int, string> the row's texts by column - a header is a text, and short
     */
    private function texts(array $row): array
    {
        $texts = [];

        foreach ($row as $column => $cell) {
            $text = trim(StoredCells::shown($cell));

            if ($text !== '' && StoredCells::kind($cell) === 'text' && mb_strlen($text) <= 80) {
                $texts[$column] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param array<int, array<string, mixed>> $row
     */
    private function allTexts(array $row): bool
    {
        foreach ($row as $cell) {
            // A formula's text counts: a whole sheet may be formulas (a Google Sheets IMPORTRANGE export)
            if (StoredCells::isBlank($cell) === false && StoredCells::kind($cell) !== 'text') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $columns ascending
     *
     * @return list<array{int, int}> runs of columns next to each other
     */
    private static function runs(array $columns): array
    {
        $runs = [];

        foreach ($columns as $column) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $column - 1) {
                $runs[$last][1] = $column;
            } else {
                $runs[] = [$column, $column];
            }
        }

        return $runs;
    }
}
