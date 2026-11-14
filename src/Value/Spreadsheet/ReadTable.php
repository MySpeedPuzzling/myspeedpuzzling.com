<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * An Excel table ("Format as table") on a sheet: where it is and where its header is.
 */
final readonly class ReadTable
{
    public function __construct(
        public string $name,
        /** Header, data and totals rows together */
        public CellRange $range,
        /** null: the table has no header row */
        public null|int $headerRow,
        public int $totalsRows = 0,
    ) {
    }

    public function firstDataRow(): int
    {
        return $this->headerRow === null ? $this->range->firstRow : $this->headerRow + 1;
    }

    public function lastDataRow(): int
    {
        return $this->range->lastRow - $this->totalsRows;
    }
}
