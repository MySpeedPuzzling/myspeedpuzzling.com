<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\DatePrecision;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormatKind;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * An opened .xlsx workbook (docs/features/data-import/reader.md).
 *
 * OpenSpout opens the workbook and reads its shared strings - every distinct text of every sheet - into our own
 * bounded store. The rows are read by one pass of our own over the sheet's XML (SheetCellStream): each cell's style,
 * type, formula mark and saved value, typed here by our classifier of the cell's number format; a text is looked up
 * in the store. One row in memory at a time, and what a row costs depends on the cells it holds - never on what the
 * sheet says about its own size.
 *
 * Why the rows are not OpenSpout's (its row iterator was built in step with our pass first, and measured): it sizes
 * every row by the sheet's <dimension> or the row's "spans" - one attribute of an untrusted file made 200,000 cells
 * take 76 s instead of 2 - and it hands back a date-styled number as an object built by its own rules in PHP's
 * default time zone, a formula's saved error as nothing, a text starting with "=" as a formula. OpenSpoutParityTest
 * holds our rows against its iterator on generated workbooks.
 */
final class XlsxWorkbook implements ReadWorkbook
{
    private const int SECONDS_PER_DAY = 86_400;
    /** Days from 1899-12-30 (serial 0 of the 1900 date system) to 1970-01-01 */
    private const int EPOCH_SERIAL_1900 = 25_569;
    /** The 1904 date system starts 1,462 days later */
    private const int EPOCH_SERIAL_1904 = 24_107;
    /** The last moment a spreadsheet date can hold: 9999-12-31 */
    private const float MAX_DATE_SERIAL = 2_958_465.99999999;

    /** @var array<int, int> a sheet's position => rows streamed from it */
    private array $rowsBySheet = [];
    /** @var array<int, int> a sheet's position => cells streamed from it */
    private array $cellsBySheet = [];
    private bool $closed = false;

    private function __construct(
        private readonly XlsxPackage $package,
        private readonly XlsxStructure $structure,
        private readonly Reader $reader,
        private readonly null|SharedStringsStore $sharedStrings,
        private readonly string $temporaryFolder,
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
        $structure = XlsxStructure::read($package, $limits);
        $temporaryFolder = self::temporaryFolder($temporaryBase);

        $stores = new SharedStringsStoreFactory();

        try {
            // Opening reads the shared strings into the store the factory hands out
            $reader = new Reader(new Options(tempFolder: $temporaryFolder), $stores);
            $reader->open($package->path);
        } catch (\Throwable $e) {
            self::removeFolder($temporaryFolder);

            throw SpreadsheetUnreadable::damaged('the workbook could not be opened', $e);
        }

        return new self($package, $structure, $reader, $stores->store, $temporaryFolder, $limits);
    }

    public function sheets(): array
    {
        return $this->structure->sheets;
    }

    public function rows(ReadSheet $sheet): \Generator
    {
        // Checked now, not at the first row: a generator's body only runs once it is iterated
        $entry = $this->entryOf($sheet);

        return $this->stream($sheet, $entry);
    }

    /**
     * @return \Generator<int, ReadRow>
     */
    private function stream(ReadSheet $sheet, string $entry): \Generator
    {
        $position = $sheet->position;
        $this->rowsBySheet[$position] = 0;
        $this->cellsBySheet[$position] = 0;
        $lastRow = 0;
        $stream = null;

        try {
            $links = $this->tail($sheet, false)[1];
            $stream = new SheetCellStream($this->package->xml($entry, $this->maxSheetNodes()));

            while (($next = $stream->nextRow()) !== null) {
                [$number, $metas] = $next;

                // A row number that goes backwards or repeats is passed over: the first one stands
                if ($number <= $lastRow) {
                    continue;
                }

                $lastRow = $number;
                $row = $metas === [] ? null : $this->row($position, $number, $metas, $links[$number] ?? []);

                if ($row !== null) {
                    yield $row;
                }
            }
        } catch (SpreadsheetUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SpreadsheetUnreadable::damaged('a sheet could not be read', $e);
        } finally {
            $stream?->close();
        }
    }

    public function mergedRanges(ReadSheet $sheet): array
    {
        try {
            return $this->tail($sheet, true)[0];
        } catch (SpreadsheetUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SpreadsheetUnreadable::damaged('a sheet could not be read', $e);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $this->reader->close();
        } catch (\Throwable) {
            // Whatever it left behind goes with the folder
        }

        self::removeFolder($this->temporaryFolder);
    }

    /**
     * @param array<int, array{int, string, bool, string}> $metas column => [style index, type, formula?, raw value]
     * @param array<int, string> $links column => the address a cell links to
     */
    private function row(int $position, int $number, array $metas, array $links): null|ReadRow
    {
        $cells = [];

        foreach ($metas as $column => [$style, $type, $formula, $raw]) {
            $cell = match ($type) {
                's' => $this->sharedStringCell($raw, $formula),
                'inlineStr', 'str' => self::textCell($raw, $formula),
                'b' => $raw === '' ? null : ReadCell::boolean($raw === '1' || strtolower($raw) === 'true', $formula),
                'e' => ReadCell::error($raw === '' ? '#VALUE!' : $raw, $formula),
                'd' => $raw === '' ? null : $this->isoDateCell($raw, $this->structure->cellFormats[$style] ?? null, $formula),
                'n' => $raw === '' ? null : $this->numberCell($raw, $this->structure->cellFormats[$style] ?? null, $formula),
                default => self::textCell($raw, $formula),
            };

            if ($cell === null) {
                continue;
            }

            if ($column > $this->limits->maxColumns) {
                throw SpreadsheetUnreadable::tooManyColumns($this->limits->maxColumns);
            }

            if (isset($links[$column]) && $links[$column] !== $cell->value) {
                $cell = $cell->withHyperlink($links[$column]);
            }

            $cells[$column] = $cell;
        }

        if ($cells === []) {
            return null;
        }

        $this->rowsBySheet[$position]++;
        $this->cellsBySheet[$position] += count($cells);

        if ($this->rowsBySheet[$position] > $this->limits->maxRowsPerSheet) {
            throw SpreadsheetUnreadable::tooManyRows($this->limits->maxRowsPerSheet);
        }

        if (array_sum($this->rowsBySheet) > $this->limits->maxRows) {
            throw SpreadsheetUnreadable::tooManyRows($this->limits->maxRows);
        }

        if (array_sum($this->cellsBySheet) > $this->limits->maxCells) {
            throw SpreadsheetUnreadable::tooManyCells($this->limits->maxCells);
        }

        return new ReadRow($number, $cells);
    }

    private function sharedStringCell(string $raw, bool $formula): null|ReadCell
    {
        if ($this->sharedStrings === null || preg_match('/^\d{1,9}$/', $raw) !== 1) {
            throw SpreadsheetUnreadable::damaged('a cell points to a shared string that is not there');
        }

        return self::textCell($this->sharedStrings->getStringAtIndex((int) $raw), $formula);
    }

    /**
     * A text as the player typed it. A spreadsheet writes a character XML can not hold as _xHHHH_ (a carriage return
     * inside a cell is _x000D_, an underscore in front of such a sequence _x005F_); control characters that are no
     * text at all are dropped.
     */
    private static function textCell(string $text, bool $formula): null|ReadCell
    {
        if (str_contains($text, '_x')) {
            $text = preg_replace_callback(
                '/_x([0-9A-Fa-f]{4})_/',
                static fn (array $match): string => (string) mb_chr((int) hexdec($match[1]), 'UTF-8'),
                $text,
            ) ?? $text;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        }

        return $text === '' ? null : ReadCell::text($text, $formula);
    }

    private function numberCell(string $raw, null|NumberFormat $format, bool $formula): ReadCell
    {
        if (is_numeric($raw) === false) {
            return ReadCell::text($raw, $formula);
        }

        $number = preg_match('/^-?\d{1,18}$/', $raw) === 1 ? (int) $raw : (float) $raw;
        $kind = $format === null ? NumberFormatKind::General : $format->kind;

        if (is_float($number) && is_finite($number) === false) {
            return ReadCell::text($raw, $formula);
        }

        // More days than a spreadsheet can count is a number, whatever its format says
        if ($kind === NumberFormatKind::Duration && abs($number) <= self::MAX_DATE_SERIAL) {
            return ReadCell::duration((int) round($number * self::SECONDS_PER_DAY), $formula);
        }

        // A negative "date" is shown as ##### by a spreadsheet; so is one beyond the year 9999
        $datable = $number >= 0 && $number <= self::MAX_DATE_SERIAL;

        if ($datable && $format !== null && ($kind === NumberFormatKind::Time || $kind === NumberFormatKind::Date)) {
            $days = (int) floor($number);
            $seconds = (int) round(($number - $days) * self::SECONDS_PER_DAY);

            if ($seconds === self::SECONDS_PER_DAY) {
                $days++;
                $seconds = 0;
            }

            // In the 1900 date system day 0 does not exist: a "date" below 1 is a time of day in a date's clothes
            if ($kind === NumberFormatKind::Time || ($days === 0 && $this->structure->date1904 === false)) {
                return ReadCell::timeOfDay($seconds, $formula);
            }

            return ReadCell::date(
                $this->dayOf($days),
                $format->precision ?? DatePrecision::Day,
                $format->yearShown,
                $format->timeShown ? $seconds : null,
                $formula,
            );
        }

        if (is_float($number) && floor($number) === $number && abs($number) < 1e15) {
            $number = (int) $number;
        }

        return ReadCell::number($number, $formula, $format !== null && $format->percent);
    }

    /**
     * A cell of type "d": an ISO 8601 date written out (strict Open XML).
     */
    private function isoDateCell(string $raw, null|NumberFormat $format, bool $formula): ReadCell
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', $raw, $match) !== 1) {
            return ReadCell::text($raw, $formula);
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new \DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $match[1]) {
            return ReadCell::text($raw, $formula);
        }

        $seconds = isset($match[2]) ? (int) $match[2] * 3600 + (int) ($match[3] ?? 0) * 60 + (int) ($match[4] ?? 0) : 0;
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

    /**
     * What stands after a sheet's rows: its merged ranges and its links to the outside. One pass in which libxml
     * skips the rows by itself. The links are only looked for when the sheet's relationships name any.
     *
     * @return array{list<CellRange>, array<int, array<int, string>>} merged ranges; row => column => address
     */
    private function tail(ReadSheet $sheet, bool $mergedRanges): array
    {
        $entry = $this->entryOf($sheet);
        $targets = [];

        foreach (XlsxStructure::relationships($this->package, $entry) as $id => $relationship) {
            if ($relationship['external'] && str_ends_with($relationship['type'], '/hyperlink') && $relationship['target'] !== '') {
                $targets[$id] = $relationship['target'];
            }
        }

        // A sheet with a link in every row of fifty thousand: its links are not worth megabytes of memory
        if (count($targets) > $this->limits->maxHyperlinksPerSheet) {
            $targets = [];
        }

        if ($targets === [] && $mergedRanges === false) {
            return [[], []];
        }

        $ranges = [];
        $links = [];
        $linkCount = 0;
        $xml = $this->package->xml($entry, 1_000_000);

        try {
            $more = $xml->read();

            while ($more) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                    $more = $xml->read();

                    continue;
                }

                $name = $xml->reader->localName;

                if ($name === 'sheetData') {
                    $more = $xml->next();

                    continue;
                }

                if ($name === 'mergeCell' && $mergedRanges && count($ranges) < $this->limits->maxMergedRangesPerSheet) {
                    $range = CellRange::fromA1((string) $xml->attribute('ref'));

                    if ($range !== null) {
                        $ranges[] = $range;
                    }
                } elseif ($name === 'hyperlink' && $targets !== [] && $linkCount < $this->limits->maxHyperlinksPerSheet) {
                    $range = CellRange::fromA1((string) $xml->attribute('ref'));
                    $target = $targets[(string) $xml->relationshipId()] ?? null;

                    if ($range !== null && $target !== null) {
                        $links[$range->firstRow][$range->firstColumn] = $target;
                        $linkCount++;
                    }
                }

                $more = $xml->read();
            }
        } finally {
            $xml->close();
        }

        return [$ranges, $links];
    }

    private function entryOf(ReadSheet $sheet): string
    {
        $known = $this->structure->sheets[$sheet->position - 1] ?? null;

        if ($this->closed || $known !== $sheet) {
            throw new \InvalidArgumentException('The sheet does not belong to this workbook, or the workbook is closed.');
        }

        return $this->structure->entries[$sheet->position];
    }

    /**
     * How many XML nodes a sheet may hold before it is refused: a cell with a formula and a value is about nine, and
     * the sheet may carry as many empty styled cells again.
     */
    private function maxSheetNodes(): int
    {
        return 40 * $this->limits->maxCells + 1_000_000;
    }

    /**
     * @return non-empty-string
     */
    private static function temporaryFolder(string $base): string
    {
        $folder = rtrim($base, '/') . '/msp-spreadsheet-' . bin2hex(random_bytes(8));

        if (@mkdir($folder, 0700) === false) {
            throw new \RuntimeException('The temporary folder can not be created.');
        }

        return $folder;
    }

    private static function removeFolder(string $folder): void
    {
        if (is_dir($folder) === false) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && $entry->isLink() === false ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }

        @rmdir($folder);
    }
}
