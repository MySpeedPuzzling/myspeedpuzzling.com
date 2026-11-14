<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Csv\CsvWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\XlsxWorkbook;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * Turns an untrusted uploaded .xlsx or .csv/.tsv file into sheets, regions and typed cells - safely and with constant
 * memory (docs/features/data-import/reader.md; design: docs/features/data-import/README.md "Reading a file", D14).
 *
 * - .xlsx: OpenSpout opens the workbook and keeps its shared strings (every distinct text) in a store of ours that
 *   stays small however many there are; passes of our own over the workbook's XML read the rest - before any row the
 *   sheets, Excel tables, frozen panes and every cell style's number format, then row by row each cell's saved value,
 *   typed by its format (a month-only date, a hidden year, a duration against a time of day). A formula is never
 *   calculated: the value is what the spreadsheet saved.
 * - .csv/.tsv: one sheet of text cells; delimiter and encoding are detected like ParticipantFileReader does.
 *
 * Nothing is trusted: size, zip structure, declared and real unpacked sizes and XML are checked before and while
 * reading, and the only thing thrown for a bad file is SpreadsheetUnreadable with its reason.
 */
final readonly class SpreadsheetReader
{
    /**
     * @param null|string $temporaryFolder where an opened file may keep what it needs while it is read (a folder of
     *                                     its own per file, removed by ReadWorkbook::close()); null = the system's
     */
    public function __construct(
        private null|string $temporaryFolder = null,
    ) {
    }

    /**
     * @throws SpreadsheetUnreadable
     */
    public function open(
        string $path,
        SpreadsheetFormat $format,
        SpreadsheetLimits $limits = new SpreadsheetLimits(),
    ): ReadWorkbook {
        $temporaryFolder = $this->temporaryFolder ?? sys_get_temp_dir();

        try {
            return match ($format) {
                SpreadsheetFormat::Xlsx => XlsxWorkbook::open($path, $limits, $temporaryFolder),
                SpreadsheetFormat::Csv => CsvWorkbook::open($path, $limits, $temporaryFolder),
            };
        } catch (SpreadsheetUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Untrusted input: whatever a library trips over is a bad file, never a surprise for the caller
            throw SpreadsheetUnreadable::damaged('the file could not be opened', $e);
        }
    }
}
