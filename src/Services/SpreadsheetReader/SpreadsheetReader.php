<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Csv\CsvWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\XlsxWorkbook;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * Turns an untrusted uploaded .xlsx or .csv/.tsv file into sheets, regions and typed cells - safely and with constant
 * memory (docs/features/data-import/reader.md; design: docs/features/data-import/README.md "Reading a file").
 *
 * - .xlsx: passes of our own over the workbook's XML, every part through one metered door (XlsxPackage) - before any
 *   row the sheets, Excel tables, frozen panes and every cell style's number format, then row by row each cell's
 *   saved value, typed by its format (a month-only date, a hidden year, a duration against a time of day). A formula
 *   is never calculated: the value is what the spreadsheet saved. No library parses the file.
 * - .csv/.tsv: one sheet of text cells; delimiter and encoding are detected like ParticipantFileReader does.
 *
 * Nothing in the file is trusted, and no string, row, part or pass over it is without a cap. What a file is or holds
 * ends in SpreadsheetUnreadable with its reason; what is the server's fault (a temporary folder that can not be
 * written) in SpreadsheetReaderFailed; a bug stays the error it is.
 */
final readonly class SpreadsheetReader
{
    /** Under the system's temporary folder, a folder of the reader's own - nothing else is ever pruned */
    public const string DEFAULT_TEMPORARY_FOLDER = 'msp-spreadsheet-reader';

    /**
     * @param null|string $temporaryFolder the folder in which an opened file makes one of its own (mode 0700,
     *                                     removed by ReadWorkbook::close()) when it has to write something - many
     *                                     shared strings, a rewritten UTF-16 text; null = a folder of the reader's
     *                                     own under the system's (DEFAULT_TEMPORARY_FOLDER)
     */
    public function __construct(
        private null|string $temporaryFolder = null,
    ) {
    }

    /**
     * @throws SpreadsheetUnreadable
     * @throws SpreadsheetReaderFailed
     */
    public function open(
        string $path,
        SpreadsheetFormat $format,
        SpreadsheetLimits $limits = new SpreadsheetLimits(),
    ): ReadWorkbook {
        return match ($format) {
            SpreadsheetFormat::Xlsx => XlsxWorkbook::open($path, $limits, $this->temporaryBase()),
            SpreadsheetFormat::Csv => CsvWorkbook::open($path, $limits, $this->temporaryBase()),
        };
    }

    /**
     * Removes the temporary folders of readers that never closed - a worker that was killed - and were last written
     * to before the given moment. For the worker's start and a daily cron.
     *
     * @return int how many folders were removed
     */
    public function pruneLeftovers(\DateTimeImmutable $lastWrittenBefore): int
    {
        return TemporaryFolder::prune($this->temporaryBase(), $lastWrittenBefore);
    }

    private function temporaryBase(): string
    {
        return $this->temporaryFolder ?? rtrim(sys_get_temp_dir(), '/') . '/' . self::DEFAULT_TEMPORARY_FOLDER;
    }
}
