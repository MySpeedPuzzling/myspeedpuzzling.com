<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormatKind;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * An opened .xlsx workbook (docs/features/data-import/reader.md).
 *
 * Everything is read by passes of our own over the workbook's XML, each part through XlsxPackage::xml(): the
 * structure when the workbook is opened (XlsxStructure), a sheet's rows when they are asked for (SheetCellStream -
 * each cell's style, type, formula mark and saved value, typed here by the cell's number format), the shared strings
 * when the first cell needs one (SharedStrings). One row in memory at a time; what a row costs depends on the cells
 * it holds, never on what the sheet says about its own size.
 *
 * Every entry point brackets its work with the budget's enter() and leave() (ReadBudget): the reader's seconds are
 * counted there, and warnings of the XML reader are kept from the application while it works.
 *
 * No library parses the file at runtime. OpenSpout's row iterator was tried first and measured (it sizes every row
 * by the sheet's <dimension>: one attribute of an untrusted file made 200,000 cells take 76 s) and its shared-strings
 * reader builds each string as a DOM; OpenSpoutParityTest still holds our rows against it on generated workbooks.
 */
final class XlsxWorkbook implements ReadWorkbook
{
    private const int SECONDS_PER_DAY = 86_400;

    /**
     * A time of a cell **whose format shows a fraction of a second** ([h]:mm:ss.00, mm:ss.0), in whole seconds: the
     * fraction the player sees is dropped, as the add-result form takes a typed time ("0:30:20.23" is 30 min 20 s,
     * "1:02:03.5" is 1:02:03) - results are whole seconds. Rounded to the millisecond first: a double is never
     * exactly the second it stands for. A cell that shows whole seconds is rounded to the second - what the player
     * sees.
     */
    private static function wholeSeconds(int|float $days): int
    {
        return (int) floor(round($days * self::SECONDS_PER_DAY, 3));
    }
    /** Days from 1899-12-30 (serial 0 of the 1900 date system) to 1970-01-01 */
    private const int EPOCH_SERIAL_1900 = 25_569;
    /** The 1904 date system starts 1,462 days later */
    private const int EPOCH_SERIAL_1904 = 24_107;
    /** The last day a spreadsheet date can be, 9999-12-31, as a serial number of the 1900 and of the 1904 date system */
    private const int LAST_DAY_1900 = 2_958_465;
    private const int LAST_DAY_1904 = 2_957_003;

    /** The elements of a sheet that stand before its merged ranges (ECMA-376 §18.3.1.99, in this order) */
    private const array BEFORE_MERGED_RANGES = [
        'worksheet' => true, 'sheetPr' => true, 'dimension' => true, 'sheetViews' => true, 'sheetFormatPr' => true, 'cols' => true,
        'sheetData' => true, 'sheetCalcPr' => true, 'sheetProtection' => true, 'protectedRanges' => true, 'scenarios' => true,
        'autoFilter' => true, 'sortState' => true, 'dataConsolidate' => true, 'customSheetViews' => true, 'mergeCells' => true,
    ];

    private int $rowsRead = 0;
    private int $cellsRead = 0;
    private bool $closed = false;
    /** @var array<int, SheetCellStream> the sheets being streamed right now: closed with the workbook */
    private array $streams = [];

    private function __construct(
        private readonly XlsxPackage $package,
        private readonly XlsxStructure $structure,
        private readonly SharedStrings $sharedStrings,
        private readonly TemporaryFolder $folder,
        private readonly SpreadsheetLimits $limits,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function open(string $path, SpreadsheetLimits $limits, string $temporaryBase): self
    {
        $package = XlsxPackage::open($path, $limits);
        $package->budget->enter();

        try {
            $structure = XlsxStructure::read($package, $limits);
        } catch (\Throwable $e) {
            $package->close();

            throw $e;
        } finally {
            $package->budget->leave();
        }

        $folder = new TemporaryFolder($temporaryBase);

        return new self($package, $structure, new SharedStrings($package, $structure->sharedStringsEntry, $folder, $limits), $folder, $limits);
    }

    public function sheets(): array
    {
        return $this->structure->sheets;
    }

    public function rows(ReadSheet $sheet, CellRange ...$windows): \Generator
    {
        // Checked now, not at the first row: a generator's body only runs once it is iterated
        $entry = $this->entryOf($sheet);

        return $this->stream($entry, array_values($windows));
    }

    public function mergedRanges(ReadSheet $sheet): array
    {
        $entry = $this->entryOf($sheet);
        $ranges = [];
        $budget = $this->package->budget;
        $budget->enter();
        $xml = null;

        try {
            $xml = $this->package->xml($entry);
            $more = $xml->read();

            while ($more) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                    $more = $xml->read();

                    continue;
                }

                $name = $xml->reader->localName;

                // The rows are skipped by the parser alone - every chunk of them still passes the part's meter
                if ($name === 'sheetData') {
                    $more = $xml->next();

                    continue;
                }

                if ($name === 'mergeCell') {
                    if (count($ranges) >= $this->limits->maxMergedRangesPerSheet) {
                        break;
                    }

                    $range = CellRange::fromA1(substr((string) $xml->attribute('ref'), 0, 32));

                    if ($range !== null) {
                        $ranges[] = $range;
                    }
                } elseif ($xml->reader->depth === 1 && isset(self::BEFORE_MERGED_RANGES[$name]) === false) {
                    // Everything that stands after the merged ranges in a sheet (conditional formats, links,
                    // extensions) is not read
                    break;
                }

                $more = $xml->read();
            }
        } finally {
            $xml?->close();
            $budget->leave();
        }

        return $ranges;
    }

    public function rowsRead(): int
    {
        return $this->rowsRead;
    }

    public function cellsRead(): int
    {
        return $this->cellsRead;
    }

    public function bytesRead(): int
    {
        return $this->package->budget->read;
    }

    public function secondsRead(): float
    {
        return $this->package->budget->nanoseconds() / 1e9;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // A sheet that is still being streamed ends here: its generator hands out no further row
        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = [];
        $this->sharedStrings->clear();
        $this->package->close();
        $this->folder->remove();
    }

    /**
     * @param list<CellRange> $windows
     *
     * @return \Generator<int, ReadRow>
     */
    private function stream(string $entry, array $windows): \Generator
    {
        $budget = $this->package->budget;
        $lastRow = 0;
        $rows = 0;
        $stream = null;

        try {
            while ($this->closed === false) {
                $out = [];
                // The reader's own time: what the caller does with a row is not counted
                $budget->enter();

                try {
                    if ($stream === null) {
                        $stream = new SheetCellStream($this->package->xml($entry), $this->limits, $windows);
                        $this->streams[spl_object_id($stream)] = $stream;
                    }

                    $next = $stream->nextRow();

                    if ($next === null) {
                        break;
                    }

                    [$number, $metas] = $next;

                    // A row number that goes backwards or repeats is passed over: the first one stands
                    if ($number <= $lastRow || $metas === []) {
                        $lastRow = max($lastRow, $number);

                        continue;
                    }

                    $lastRow = $number;
                    $cells = $this->cells($metas);

                    foreach ($windows === [] ? [null] : $windows as $index => $window) {
                        $inWindow = $window === null ? $cells : self::inside($window, $number, $cells);

                        if ($inWindow === []) {
                            continue;
                        }

                        $this->rowsRead++;
                        $this->cellsRead += count($inWindow);
                        $out[] = [$index, new ReadRow($number, $inWindow)];

                        if (++$rows > $this->limits->maxRowsPerSheet) {
                            throw SpreadsheetUnreadable::tooManyRows($this->limits->maxRowsPerSheet);
                        }

                        if ($this->rowsRead > $this->limits->maxRows) {
                            throw SpreadsheetUnreadable::tooManyRows($this->limits->maxRows);
                        }

                        if ($this->cellsRead > $this->limits->maxCells) {
                            throw SpreadsheetUnreadable::tooManyCells($this->limits->maxCells);
                        }
                    }

                    if (($rows & 1023) === 0) {
                        $this->folder->keepAlive();
                    }
                } finally {
                    $budget->leave();
                }

                foreach ($out as [$index, $row]) {
                    yield $index => $row;
                }
            }
        } finally {
            if ($stream !== null) {
                unset($this->streams[spl_object_id($stream)]);
                $stream->close();
            }
        }
    }

    /**
     * The cells of a row that stand inside a window.
     *
     * @param array<int, ReadCell> $cells
     *
     * @return array<int, ReadCell>
     */
    private static function inside(CellRange $window, int $row, array $cells): array
    {
        if ($row < $window->firstRow || $row > $window->lastRow) {
            return [];
        }

        $inside = [];

        foreach ($cells as $column => $cell) {
            if ($column >= $window->firstColumn && $column <= $window->lastColumn) {
                $inside[$column] = $cell;
            }
        }

        return $inside;
    }

    /**
     * @param non-empty-array<int, array{int, string, bool, string}> $metas column => [style index, type, formula?, raw value]
     *
     * @return array<int, ReadCell>
     */
    private function cells(array $metas): array
    {
        $cells = [];
        $textBytes = 0;

        foreach ($metas as $column => [$style, $type, $formula, $raw]) {
            $cell = match ($type) {
                's' => $this->sharedStringCell($raw, $formula),
                'inlineStr', 'str' => self::textCell(SharedStrings::decoded($raw), $formula),
                'b' => $raw === '' ? null : ReadCell::boolean($raw === '1' || strtolower($raw) === 'true', $formula),
                'e' => ReadCell::error($raw === '' ? '#VALUE!' : mb_strcut(ReadCell::withoutControls($raw), 0, 32, 'UTF-8'), $formula),
                'd' => $raw === '' ? null : self::isoDateCell($raw, $this->structure->cellFormats[$style] ?? null, $formula),
                'n' => $raw === '' ? null : $this->numberCell($raw, $this->structure->cellFormats[$style] ?? null, $formula),
                default => self::textCell(SharedStrings::decoded($raw), $formula),
            };

            if ($cell === null) {
                continue;
            }

            // A row of a thousand cells that each point to the longest shared string is not a megabyte of file
            if (is_string($cell->value)) {
                $textBytes += strlen($cell->value);

                if ($textBytes > $this->limits->maxRowBytes) {
                    throw SpreadsheetUnreadable::cellTooLarge(sprintf('a row of more than %d bytes', $this->limits->maxRowBytes));
                }
            }

            $cells[$column] = $cell;
        }

        return $cells;
    }

    private function sharedStringCell(string $raw, bool $formula): null|ReadCell
    {
        if (strlen($raw) > 9 || ctype_digit($raw) === false) {
            throw SpreadsheetUnreadable::damaged('a cell points to a shared string that is not there');
        }

        return self::textCell($this->sharedStrings->get((int) $raw), $formula);
    }

    private static function textCell(string $text, bool $formula): null|ReadCell
    {
        return $text === '' ? null : ReadCell::text($text, $formula);
    }

    private function numberCell(string $raw, null|NumberFormat $format, bool $formula): null|ReadCell
    {
        // A number is a few dozen characters at most; what is not a number is handed over as the text it is
        if (strlen($raw) > 64 || is_numeric($raw) === false) {
            return self::textCell(SharedStrings::decoded($raw), $formula);
        }

        $number = preg_match('/^-?\d{1,18}$/', $raw) === 1 ? (int) $raw : (float) $raw;

        if (is_float($number) && is_finite($number) === false) {
            return self::textCell($raw, $formula);
        }

        $kind = $format === null ? NumberFormatKind::General : $format->kind;

        // More days than a spreadsheet can count is a number, whatever its format says
        if ($kind === NumberFormatKind::Duration && abs($number) <= self::LAST_DAY_1900 + 1) {
            return ReadCell::duration($format !== null && $format->secondFractionShown ? self::wholeSeconds($number) : (int) round($number * self::SECONDS_PER_DAY), $formula);
        }

        // A negative "date" is shown as ##### by a spreadsheet; so is one beyond the year 9999 - which a workbook
        // that counts from 1904 reaches 1,462 days sooner
        $lastDay = $this->structure->date1904 ? self::LAST_DAY_1904 : self::LAST_DAY_1900;

        if ($number >= 0 && $number < $lastDay + 1 && $format !== null && ($kind === NumberFormatKind::Time || $kind === NumberFormatKind::Date)) {
            // Seconds first, then the split into day and time: 23:59:59.7 is midnight of the next day
            $moment = (int) round($number * self::SECONDS_PER_DAY);
            $seconds = $moment % self::SECONDS_PER_DAY;

            // In the 1900 date system day 0 does not exist: a "date" below 1 is a time of day in a date's clothes
            if ($kind === NumberFormatKind::Time || ($number < 1 && $this->structure->date1904 === false)) {
                return ReadCell::timeOfDay($format->secondFractionShown ? self::wholeSeconds($number) % self::SECONDS_PER_DAY : $seconds, $formula);
            }

            // A cell that shows no time is its day - however close to midnight its number is
            $day = $format->timeShown ? intdiv($moment, self::SECONDS_PER_DAY) : (int) floor($number);

            // The last second of the year 9999, rounded up, is no date any more
            if ($day <= $lastDay) {
                return ReadCell::date(
                    $this->dayOf($day),
                    $format->precision ?? DatePrecision::Day,
                    $format->yearShown,
                    $format->timeShown ? $seconds : null,
                    $formula,
                );
            }
        }

        if (is_float($number) && floor($number) === $number && abs($number) < 1e15) {
            $number = (int) $number;
        }

        return ReadCell::number($number, $formula, $format !== null && $format->percent, $format === null ? 0 : $format->zeroPadded);
    }

    /**
     * A cell of type "d": an ISO 8601 date written out (strict Open XML). What is no date there is an error value.
     */
    private static function isoDateCell(string $raw, null|NumberFormat $format, bool $formula): ReadCell
    {
        if (strlen($raw) > 40 || preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $raw, $match) !== 1) {
            return ReadCell::error('#VALUE!', $formula);
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new \DateTimeZone('UTC'));
        $hours = (int) ($match[2] ?? 0);
        $minutes = (int) ($match[3] ?? 0);
        $secondsOfMinute = (int) ($match[4] ?? 0);

        if ($day === false || $day->format('Y-m-d') !== $match[1] || $hours > 23 || $minutes > 59 || $secondsOfMinute > 59) {
            return ReadCell::error('#VALUE!', $formula);
        }

        $seconds = $hours * 3600 + $minutes * 60 + $secondsOfMinute;
        $isDate = $format !== null && $format->kind === NumberFormatKind::Date;

        return ReadCell::date(
            $day,
            $isDate ? $format->precision ?? DatePrecision::Day : DatePrecision::Day,
            $isDate ? $format->yearShown : true,
            ($isDate ? $format->timeShown : $seconds > 0) ? $seconds : null,
            $formula,
        );
    }

    /**
     * The calendar day of a whole serial number. The 1900 system counts a 29 February 1900 that never was (serial
     * 60), so days before it sit one later than plain counting from 1899-12-30 says.
     */
    private function dayOf(int $serial): \DateTimeImmutable
    {
        $daysSinceEpoch = match (true) {
            $this->structure->date1904 => $serial - self::EPOCH_SERIAL_1904,
            $serial < 60 => $serial - self::EPOCH_SERIAL_1900 + 1,
            default => $serial - self::EPOCH_SERIAL_1900,
        };

        $day = \DateTimeImmutable::createFromFormat('U', (string) ($daysSinceEpoch * self::SECONDS_PER_DAY));
        \assert($day !== false);

        return $day->setTimezone(new \DateTimeZone('UTC'));
    }

    private function entryOf(ReadSheet $sheet): string
    {
        $known = $this->structure->sheets[$sheet->position - 1] ?? null;

        if ($this->closed || $known !== $sheet) {
            throw new \InvalidArgumentException('The sheet does not belong to this workbook, or the workbook is closed.');
        }

        return $this->structure->entries[$sheet->position];
    }
}
