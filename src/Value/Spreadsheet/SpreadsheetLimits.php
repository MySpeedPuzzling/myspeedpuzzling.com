<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * How big a spreadsheet SpreadsheetReader reads (docs/features/data-import/reader.md "Limits and reasons"). The
 * defaults are the import's limits (docs/features/data-import/README.md Q8: 25 MB, 100 sheets, 50,000 rows,
 * 1,000,000 cells) plus the caps that keep one hostile file from costing more than seconds and megabytes.
 *
 * maxRows, maxCells, maxReadBytes and maxReadSeconds (CPU time) are **budgets of an import**, not of one opened file: a job that
 * reads one sheet passes what is left to the next - withConsumed() and ReadWorkbook's rowsRead(), cellsRead(),
 * bytesRead(), secondsRead(). Rows and cells count what was handed out (read twice = counted twice).
 */
final readonly class SpreadsheetLimits
{
    public function __construct(
        public int $maxFileBytes = 25 * 1024 * 1024,
        /** Tabs of a workbook, chart sheets included */
        public int $maxSheets = 100,
        /** Rows still allowed to be read (all sheets together) */
        public int $maxRows = 50_000,
        /** Rows of one rows() call */
        public int $maxRowsPerSheet = 50_000,
        /** How wide a sheet may be at all: the right-most column that may hold a value (a sanity cap - how many
         *  columns a mapped region may have is the next layer's rule) */
        public int $maxColumns = 1_000,
        /** Cells still allowed to be read (all sheets together) */
        public int $maxCells = 1_000_000,
        /** One cell's text. A spreadsheet cell holds 32,767 characters - of up to 4 bytes each */
        public int $maxCellBytes = 131_072,
        /** The cells of one row together; one line and one record of a text file */
        public int $maxRowBytes = 1_048_576,
        /** Parts of an .xlsx archive (a workbook with a picture per row has thousands) */
        public int $maxZipEntries = 10_000,
        /** What an .xlsx says it unpacks to, all parts together, pictures included */
        public int $maxUncompressedBytes = 512 * 1024 * 1024,
        /** Unpacked size ÷ packed size, of the archive and of any part over 1 MiB (sheet XML packs 10-40×, a zip bomb 1000×) */
        public int $maxCompressionRatio = 200,
        /** Bytes still allowed to be unpacked and parsed (a text file: read) - every pass over every part counts,
         *  and every job that opens the file again pays its structure and its shared strings again */
        public int $maxReadBytes = 512 * 1024 * 1024,
        /** Seconds of **CPU time** the reader may still spend on the file (the process's own, user + system; its own
         *  work, not the caller's between two rows) - the same on a quiet and on a busy server */
        public float $maxReadSeconds = 60.0,
        /** Distinct texts of a workbook. Not a budget: every job that needs them reads them all */
        public int $maxSharedStrings = 1_000_000,
        /** Those texts together - what the reader may put into its temporary folder */
        public int $maxSharedStringBytes = 128 * 1024 * 1024,
        /** Merged ranges of one sheet handed out */
        public int $maxMergedRangesPerSheet = 5_000,
        /** Wall-clock seconds one opened file may take of the reader's own time - the last resort when time passes
         *  that is no CPU time. Not a budget carried from job to job: every opened file gets it whole */
        public float $maxWallSeconds = 300.0,
    ) {
    }

    /**
     * The limits for the next job on the same import: what is left of the budgets.
     */
    public function withConsumed(int $rows, int $cells, int $bytes = 0, float $seconds = 0.0): self
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
            maxReadBytes: max(0, $this->maxReadBytes - $bytes),
            maxReadSeconds: max(0.0, $this->maxReadSeconds - $seconds),
            maxSharedStrings: $this->maxSharedStrings,
            maxSharedStringBytes: $this->maxSharedStringBytes,
            maxMergedRangesPerSheet: $this->maxMergedRangesPerSheet,
            maxWallSeconds: $this->maxWallSeconds,
        );
    }
}
