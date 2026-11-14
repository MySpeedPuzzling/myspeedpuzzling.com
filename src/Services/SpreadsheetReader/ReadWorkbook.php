<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;

/**
 * An opened spreadsheet file (SpreadsheetReader::open()). Listing the sheets reads no row; a sheet's rows are streamed,
 * one row in memory at a time. close() it when done - it holds a temporary folder.
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
     * @return \Generator<int, ReadRow>
     *
     * @throws SpreadsheetUnreadable while iterating: a limit is reached, or the sheet turns out to be damaged
     * @throws \InvalidArgumentException at once: the sheet is another workbook's, or this one is closed
     */
    public function rows(ReadSheet $sheet): \Generator;

    /**
     * The merged ranges of a sheet; the value of a merged range sits in its first cell. One quick pass over the sheet.
     *
     * @return list<CellRange>
     *
     * @throws SpreadsheetUnreadable
     * @throws \InvalidArgumentException the sheet is another workbook's, or this one is closed
     */
    public function mergedRanges(ReadSheet $sheet): array;

    /**
     * Lets go of the file and removes what reading left in the temporary folder. Safe to call twice.
     */
    public function close(): void;
}
