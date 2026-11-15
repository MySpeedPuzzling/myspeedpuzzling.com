<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;

/**
 * What `data_import_row.cells` keeps of a row the reader handed over (docs/features/data-import/reading.md "What a
 * row keeps"): per column (its number as the key, 1 = A) the typed value and the text the player saw - everything a
 * later step needs, because the file is not opened again.
 *
 *   k  the cell's kind (CellKind's value)
 *   v  the value: a text or an error's code as it is · a number · a date as "Y-m-d" · seconds of a duration or a
 *      time of day · true / false
 *   s  the shown text - left out when it says the same as `v` (every text cell)
 *   f  true: the value is a formula's saved result
 *   p  a date's precision (DatePrecision's value) - left out for a day
 *   y  false: the date's format hides the year
 *   t  the seconds of the time of day a date's format shows next to it
 *   z  the digits a number's format pads to with zeros (a code: the barcode keeps its leading zeros in `s`)
 */
final class ImportRowCells
{
    /**
     * @return array<int, array<string, mixed>> column (1 = A) => what is kept of its cell; stored as a JSON object
     */
    public static function of(ReadRow $row): array
    {
        $cells = [];

        foreach ($row->cells as $column => $cell) {
            $cells[$column] = self::cell($cell);
        }

        return $cells;
    }

    /**
     * About how many bytes the row takes once stored - for the import's cap on what it keeps, without encoding it
     * twice.
     */
    public static function weight(ReadRow $row): int
    {
        $bytes = 0;

        foreach ($row->cells as $cell) {
            $bytes += 24 + strlen($cell->shown) + (is_string($cell->value) ? strlen($cell->value) : 8);
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function cell(ReadCell $cell): array
    {
        $value = $cell->value instanceof \DateTimeImmutable ? $cell->value->format('Y-m-d') : $cell->value;
        $stored = ['k' => $cell->kind->value, 'v' => $value];

        if ($cell->shown !== (is_int($value) ? (string) $value : $value)) {
            $stored['s'] = $cell->shown;
        }

        if ($cell->isFormula) {
            $stored['f'] = true;
        }

        if ($cell->kind === CellKind::Date) {
            if ($cell->precision !== null && $cell->precision->value !== 'day') {
                $stored['p'] = $cell->precision->value;
            }

            if ($cell->yearShown === false) {
                $stored['y'] = false;
            }

            if ($cell->timeSeconds !== null) {
                $stored['t'] = $cell->timeSeconds;
            }
        }

        if ($cell->paddedDigits !== null) {
            $stored['z'] = $cell->paddedDigits;
        }

        return $stored;
    }
}
