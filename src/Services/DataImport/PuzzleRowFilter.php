<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportRowLeftOut;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * Tells the rows of a sheet that are no data (docs/features/data-import/README.md "Which rows are puzzles"):
 * rows outside the table, the header and its repeats, blank rows, rows of nothing but default values (a table
 * stretched to row 1,000 with unticked checkboxes), template rows (a results row with the puzzle prefilled but no
 * date and no time), totals and averages under the data - and, once a row was read, rows that are no puzzle at all.
 *
 * Nothing here is an error: such a row is listed under "Left out" with its reason and can be put back.
 */
final readonly class PuzzleRowFilter
{
    /** Folded first words of a row that sums the rows above it up */
    private const array SUMMARY_WORDS = [
        'total', 'totals', 'sum', 'average', 'avg', 'mean', 'median', 'count', 'subtotal', 'grand total',
        'celkem', 'soucet', 'prumer', 'pocet', 'summe', 'gesamt', 'durchschnitt', 'anzahl', 'mittelwert',
        'suma', 'promedio', 'media', 'somme', 'moyenne', 'nombre', '合計', '平均',
    ];

    /**
     * Before a row is read.
     *
     * @param array<int, array<string, mixed>> $cells the whole row, by column
     * @param array<int, string> $headers the table's header texts by column
     */
    public function leftOut(int $rowNumber, array $cells, ImportMapping $mapping, array $headers): null|ImportRowLeftOut
    {
        $table = $mapping->table();

        if ($mapping->isImported() === false || $table === null) {
            return ImportRowLeftOut::SheetLeftOut;
        }

        if ($table->headerRow === $rowNumber) {
            return ImportRowLeftOut::Header;
        }

        if ($table->holdsRow($rowNumber) === false) {
            return ImportRowLeftOut::OutsideTable;
        }

        $cells = array_filter($cells, static fn (int $column): bool => $table->holdsColumn($column), ARRAY_FILTER_USE_KEY);
        $filled = array_filter($cells, static fn (array $cell): bool => StoredCells::isBlank($cell) === false);

        if ($filled === []) {
            // Blank within the table - or values only beside it
            return $cells === [] ? ImportRowLeftOut::OutsideTable : ImportRowLeftOut::Blank;
        }

        if (array_filter($filled, static fn (array $cell): bool => StoredCells::isDefault($cell) === false) === []) {
            return ImportRowLeftOut::DefaultsOnly;
        }

        if (self::repeatsTheHeader($filled, $headers)) {
            return ImportRowLeftOut::RepeatedHeader;
        }

        if (self::isSummary($filled, $mapping)) {
            return ImportRowLeftOut::Summary;
        }

        if (self::isTemplate($cells, $mapping)) {
            return ImportRowLeftOut::Template;
        }

        return null;
    }

    /**
     * After a row was read: is it a puzzle? A puzzle row has a name and something that says which puzzle or what
     * happened - brand, pieces or a barcode (its own or looked up), a result, or the source's own id. A row with
     * brand and pieces - or a result - but no name is a problem to fix ($nameMissing), not left out.
     *
     * @return array{null|ImportRowLeftOut, bool} why it is left out (null = it is data), and whether its name is missing
     */
    public function judge(bool $hasName, bool $hasBrand, bool $hasPieces, bool $hasBarcode, bool $hasResult, bool $hasSourceId): array
    {
        if ($hasName) {
            return [$hasBrand || $hasPieces || $hasBarcode || $hasResult || $hasSourceId ? null : ImportRowLeftOut::NotAPuzzle, false];
        }

        if (($hasBrand && $hasPieces) || ($hasPieces && $hasResult) || $hasBarcode || $hasSourceId) {
            return [null, true];
        }

        return [ImportRowLeftOut::NotAPuzzle, false];
    }

    /**
     * @param array<int, array<string, mixed>> $filled
     * @param array<int, string> $headers
     */
    private static function repeatsTheHeader(array $filled, array $headers): bool
    {
        $same = 0;

        foreach ($filled as $column => $cell) {
            if (trim(StoredCells::shown($cell)) !== ($headers[$column] ?? '')) {
                return false;
            }

            $same++;
        }

        return $same >= 2;
    }

    /**
     * @param array<int, array<string, mixed>> $filled
     */
    private static function isSummary(array $filled, ImportMapping $mapping): bool
    {
        $firstColumn = array_key_first($filled);

        if ($firstColumn === null) {
            return false;
        }

        $first = SearchText::fold(trim(StoredCells::shown($filled[$firstColumn]), " \t:="));
        $name = $mapping->column(ImportField::PuzzleName);

        // "Total", "Average:" in front of the row - with numbers after it, or alone
        if (in_array($first, self::SUMMARY_WORDS, true)) {
            // In the puzzle's own column the word may be a puzzle's name - unless nothing but numbers follows it
            return $name === null
                || $firstColumn !== $name->column
                || array_filter(array_slice($filled, 1, null, true), static fn (array $cell): bool => StoredCells::kind($cell) === 'text' && StoredCells::isFormula($cell) === false) === [];
        }

        // Worked-out numbers under the data with no puzzle beside them: sums and averages. (A text a formula gave is
        // a value like any other - a whole sheet may be formulas.)
        return $name !== null
            && isset($filled[$name->column]) === false
            && count($filled) <= 4
            && array_filter($filled, static fn (array $cell): bool => StoredCells::isFormula($cell) === false || StoredCells::kind($cell) === 'text') === [];
    }

    /**
     * A results row waiting to be filled in: the sheet has a date or a time column, the row has neither - and no
     * other sign of a result.
     *
     * @param array<int, array<string, mixed>> $cells
     */
    private static function isTemplate(array $cells, ImportMapping $mapping): bool
    {
        if ($mapping->rowMeaning !== DataImportRowMeaning::Result) {
            return false;
        }

        $date = $mapping->column(ImportField::SolvedOn);
        $time = $mapping->column(ImportField::Time);
        $name = $mapping->column(ImportField::PuzzleName);

        // The puzzle is what is prefilled: a row without it is no template
        if (($date === null && $time === null) || $name === null || StoredCells::isBlank($cells[$name->column] ?? null)) {
            return false;
        }

        foreach ([$date, $time, $mapping->column(ImportField::AlreadyOnMySpeedPuzzling)] as $column) {
            if ($column !== null && StoredCells::isDefault($cells[$column->column] ?? null) === false && self::isZeroTime($cells[$column->column] ?? null) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param null|array<mixed> $cell
     */
    private static function isZeroTime(null|array $cell): bool
    {
        return in_array($cell['k'] ?? null, ['duration', 'time_of_day'], true) && ($cell['v'] ?? null) === 0;
    }
}
