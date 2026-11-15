<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;

/**
 * What `data_import_sheet.hints` keeps of what a file says about a sheet before any row (docs/features/data-import/reading.md
 * "What a sheet keeps") - for the step that finds the header and the tables without opening the file again:
 *
 *   tables      [{name, range ("A1:F20"), headerRow (null = none), totalsRows}] - Excel's "Format as table"
 *   frozenPane  {row, column}: the first row and column that scroll (1 = nothing frozen that way)
 *   usedRange   what the file says its values span ("A1:K200") - a hint, never a promise
 */
final class ImportSheetHints
{
    /**
     * @return null|array<string, mixed> null: the file says nothing about the sheet
     */
    public static function of(ReadSheet $sheet): null|array
    {
        $hints = [];

        foreach ($sheet->tables as $table) {
            $hints['tables'][] = [
                'name' => $table->name,
                'range' => $table->range->toA1(),
                'headerRow' => $table->headerRow,
                'totalsRows' => $table->totalsRows,
            ];
        }

        if ($sheet->frozenPane !== null) {
            $hints['frozenPane'] = ['row' => $sheet->frozenPane->firstUnfrozenRow, 'column' => $sheet->frozenPane->firstUnfrozenColumn];
        }

        if ($sheet->usedRange !== null) {
            $hints['usedRange'] = $sheet->usedRange->toA1();
        }

        return $hints === [] ? null : $hints;
    }
}
