<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What the recognisers see of a sheet: its first rows (DataImportLimits::SAMPLE_ROWS) as they are stored
 * (Services\DataImport\ImportRowCells), what the file says about it (ImportSheetHints) and how large it is. Never the
 * whole sheet - a sheet may hold 50,000 rows.
 */
final readonly class SheetSample
{
    /**
     * @param array<int, array<int, array<string, mixed>>> $rows the spreadsheet's row number => column (1 = A) => the
     *        stored cell, in the sheet's order
     * @param null|array<mixed> $hints `data_import_sheet.hints`
     * @param int $rowsTotal rows with a value on the whole sheet
     */
    public function __construct(
        public string $title,
        public array $rows,
        public null|array $hints = null,
        public int $rowsTotal = 0,
    ) {
    }

    /**
     * Whether the sheet goes on below what was sampled.
     */
    public function isCut(): bool
    {
        return $this->rowsTotal > count($this->rows);
    }
}
