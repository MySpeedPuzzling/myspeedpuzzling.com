<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports carry text other people typed (guest names, borrowers, buyers). A spreadsheet runs a cell starting
 * with "=" as a formula, so text never reaches a cell as anything but text: XLSX cells are written as strings
 * explicitly, and CSV text that a spreadsheet would read as a formula gets a leading apostrophe (OWASP's advice).
 * Numbers stay numbers; booleans are written "true"/"false" like the results export always did.
 */
final class SpreadsheetSafeValue
{
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function writeXlsxCell(Worksheet $sheet, int $column, int $row, null|bool|int|float|string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_int($value) || is_float($value)) {
            $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_NUMERIC);

            return;
        }

        $sheet->setCellValueExplicit([$column, $row], self::text($value), DataType::TYPE_STRING);
    }

    public static function forCsv(null|bool|int|float|string $value): string
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $value;
        }

        return self::text($value);
    }

    private static function text(null|bool|int|float|string $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
