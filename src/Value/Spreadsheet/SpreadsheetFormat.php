<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * What an uploaded spreadsheet is read as (docs/features/data-import/reader.md). Decided by the file name only - the
 * reader refuses a file whose content does not fit.
 */
enum SpreadsheetFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public static function fromFileName(string $fileName): null|self
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'xlsx', 'xlsm' => self::Xlsx,
            'csv', 'tsv', 'txt' => self::Csv,
            default => null,
        };
    }
}
