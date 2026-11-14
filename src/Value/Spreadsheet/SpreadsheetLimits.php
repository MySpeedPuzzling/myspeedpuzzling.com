<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * How big a spreadsheet SpreadsheetReader reads (docs/features/data-import/reader.md "Limits and reasons"). The
 * defaults are the import's limits (docs/features/data-import/README.md Q8: 25 MB, 100 sheets, 50,000 rows,
 * 1,000,000 cells) plus the caps that keep one hostile file from costing more than seconds and megabytes.
 *
 * Rows and cells are counted as they are handed out (a row or a cell that holds a value; read twice = counted
 * twice). maxRows and maxCells are a budget: a job that reads one sheet passes what is left of it to the next -
 * withConsumed() and ReadWorkbook::rowsRead() / cellsRead().
 */
final readonly class SpreadsheetLimits
{
    public function __construct(
        public int $maxFileBytes = 25 * 1024 * 1024,
        /** Tabs of a workbook, chart sheets included */
        public int $maxSheets = 100,
        /** Rows still allowed to be read from this opened file (all sheets together) */
        public int $maxRows = 50_000,
        /** Rows of one rows() call */
        public int $maxRowsPerSheet = 50_000,
        /** How wide a sheet may be at all: the right-most column that may hold a value (a sanity cap - how many
         *  columns a mapped region may have is the next layer's rule) */
        public int $maxColumns = 1_000,
        /** Cells still allowed to be read from this opened file (all sheets together) */
        public int $maxCells = 1_000_000,
        /** One cell's text. A spreadsheet cell holds 32,767 characters - of up to 4 bytes each */
        public int $maxCellBytes = 131_072,
        /** The cells of one row together; one line of a text file */
        public int $maxRowBytes = 1_048_576,
        /** Parts of an .xlsx archive (a workbook with a picture per row has thousands) */
        public int $maxZipEntries = 10_000,
        /** What an .xlsx says it unpacks to, all parts together, pictures included */
        public int $maxUncompressedBytes = 512 * 1024 * 1024,
        /** Unpacked size ÷ packed size, of the archive and of any part over 1 MiB (sheet XML packs 10-40×, a zip bomb 1000×) */
        public int $maxCompressionRatio = 200,
        /** XML really unpacked and parsed from one opened .xlsx - every pass over every part counts, so this is
         *  the bound on the time a hostile workbook can cost */
        public int $maxReadBytes = 256 * 1024 * 1024,
        /** Merged ranges of one sheet handed out */
        public int $maxMergedRangesPerSheet = 5_000,
    ) {
    }

    /**
     * The limits for the next job on the same import: what is left of the row and cell budgets.
     */
    public function withConsumed(int $rows, int $cells): self
    {
        return new self(
            maxFileBytes: $this->maxFileBytes,
            maxSheets: $this->maxSheets,
            maxRows: max(0, $this->maxRows - $rows),
            maxRowsPerSheet: $this->maxRowsPerSheet,
            maxColumns: $this->maxColumns,
            maxCells: max(0, $this->maxCells - $cells),
            maxCellBytes: $this->maxCellBytes,
            maxRowBytes: $this->maxRowBytes,
            maxZipEntries: $this->maxZipEntries,
            maxUncompressedBytes: $this->maxUncompressedBytes,
            maxCompressionRatio: $this->maxCompressionRatio,
            maxReadBytes: $this->maxReadBytes,
            maxMergedRangesPerSheet: $this->maxMergedRangesPerSheet,
        );
    }
}
