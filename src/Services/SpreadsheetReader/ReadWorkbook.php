<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;

/**
 * An opened spreadsheet file (SpreadsheetReader::open()). Listing the sheets reads no row; a sheet's rows are streamed,
 * one row in memory at a time. close() it when done - it holds the file open and may hold a temporary folder.
 */
interface ReadWorkbook
{
    /**
     * @return non-empty-list<ReadSheet> in tab order; a CSV file is one sheet
     */
    public function sheets(): array;

    /**
     * The rows of a sheet that hold a value, from the top. Can be called again for the same sheet (reading starts over).
     *
     * With windows only the cells inside them are handed out, **in one pass over the sheet**: for every row, one
     * ReadRow per window it has cells in - the generator's key is the window's index (0 for the first; 0 as well when
     * there is no window). Rows above the first window are skipped cheaply, reading stops below the last, cells
     * outside every window are neither handed out nor counted. Twenty tables side by side are twenty windows of one
     * call.
     *
     * @return \Generator<int, ReadRow>
     *
     * @throws SpreadsheetUnreadable while iterating: a limit is reached, or the sheet turns out to be damaged
     * @throws SpreadsheetReaderFailed while iterating: the temporary folder can not be written
     * @throws \InvalidArgumentException at once: the sheet is another workbook's, or this one is closed
     */
    public function rows(ReadSheet $sheet, CellRange ...$windows): \Generator;

    /**
     * The merged ranges of a sheet; the value of a merged range sits in its first cell. One pass over the sheet in
     * which its rows are skipped, not read.
     *
     * @return list<CellRange>
     *
     * @throws SpreadsheetUnreadable
     * @throws \InvalidArgumentException the sheet is another workbook's, or this one is closed
     */
    public function mergedRanges(ReadSheet $sheet): array;

    /**
     * Rows handed out so far by this opened file, all rows() calls together. With cellsRead(), bytesRead() and
     * secondsRead() what a job that reads one sheet takes off the budgets of the next
     * (SpreadsheetLimits::withConsumed()).
     */
    public function rowsRead(): int;

    /**
     * Cells handed out so far by this opened file.
     */
    public function cellsRead(): int;

    /**
     * Bytes read from the file so far - of a workbook the XML it unpacked to, every pass over every part.
     */
    public function bytesRead(): int;

    /**
     * Seconds the reader itself has spent on this opened file so far (not what the caller did between two rows).
     */
    public function secondsRead(): float;

    /**
     * Lets go of the file and removes what reading left in the temporary folder. Safe to call twice; never throws.
     */
    public function close(): void;
}
