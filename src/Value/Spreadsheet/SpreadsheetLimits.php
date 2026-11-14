<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * How big a spreadsheet SpreadsheetReader reads. The defaults are the import's limits (docs/features/data-import/
 * README.md Q8: 25 MB, 100 sheets, 50,000 rows, 60 columns, 1,000,000 cells); tests pass smaller ones.
 *
 * Rows and cells are counted as they are streamed: a row is one that holds a value, a cell is one that holds a value.
 * The totals are over every sheet streamed from one opened workbook (a sheet streamed again counts once). A sheet
 * over a limit is refused while it is streamed - the workbook's other sheets can still be read.
 */
final readonly class SpreadsheetLimits
{
    public function __construct(
        public int $maxFileBytes = 25 * 1024 * 1024,
        public int $maxSheets = 100,
        /** Rows of all sheets together */
        public int $maxRows = 50_000,
        public int $maxRowsPerSheet = 50_000,
        /** The right-most column that may hold a value */
        public int $maxColumns = 60,
        /** Cells of all sheets together */
        public int $maxCells = 1_000_000,
        /** Parts of an .xlsx archive (a workbook with a picture per row has thousands) */
        public int $maxZipEntries = 10_000,
        /** What an .xlsx unpacks to, all parts together */
        public int $maxUncompressedBytes = 512 * 1024 * 1024,
        /** Unpacked size ÷ packed size, of the archive and of any part over 1 MiB (sheet XML packs 10-40×, a zip bomb 1000×) */
        public int $maxCompressionRatio = 200,
        /** Links of one sheet kept while it is streamed; a sheet with more is read without its links */
        public int $maxHyperlinksPerSheet = 10_000,
        /** Merged ranges of one sheet handed out */
        public int $maxMergedRangesPerSheet = 5_000,
    ) {
    }
}
