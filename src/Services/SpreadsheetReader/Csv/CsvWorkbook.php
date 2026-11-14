<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Csv;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * An opened CSV/TSV text file: one sheet, every cell a text exactly as written (typing "0:56:4" or "12.03.2024" is
 * the next layer's job). The file is never loaded whole - it is looked through once in chunks (is it text, how long
 * are its lines, which encoding), then parsed record by record.
 *
 * - Encoding: a byte-order mark, UTF-16 without one, valid UTF-8, else Windows-1250 or Windows-1252 (CsvEncoding).
 * - Delimiter: Excel's `sep=;` first line, else the one of `,` `;` tab that gives the first records the same number
 *   of fields - never the most frequent character (a `;` file often holds "Solo, Pair" in a cell).
 * - Quotes as in RFC 4180 (a cell may hold delimiters, quotes and line breaks); lines end with \r\n, \n or a bare \r.
 * - A row's number is the record's number as a spreadsheet shows the file (a `sep=` line is not a row; a record with
 *   line breaks inside a cell is one row); a record without a value is not handed out.
 * - Bounded: no line is longer than a row may be (checked before anything is parsed, so the parser is never handed
 *   an endless line); a record - which a quote that never closes stretches over every line after it - is no longer
 *   than that either (the parser reads a window of the file, never the file); a record has no more fields than a
 *   sheet has columns, empty ones count; and a cell is no longer than a cell may be.
 *
 * UTF-16 files and files with bare \r line ends are first rewritten as UTF-8 with \n into the reader's temporary
 * folder - close() removes the copy.
 */
final class CsvWorkbook implements ReadWorkbook
{
    private const int SAMPLE_BYTES = 262_144;
    private const int CHUNK_BYTES = 1_048_576;
    private const int DELIMITER_SAMPLE_RECORDS = 20;
    private const array DELIMITERS = [',', ';', "\t"];

    private int $rowsRead = 0;
    private int $cellsRead = 0;
    private bool $closed = false;

    private function __construct(
        /** The file that is parsed: the upload itself, or its rewritten copy */
        private readonly string $path,
        private readonly TemporaryFolder $folder,
        private readonly CsvEncoding $encoding,
        private readonly string $delimiter,
        /** Where the first record starts (after a byte-order mark and a `sep=` line) */
        private readonly int $dataOffset,
        private readonly ReadSheet $sheet,
        private readonly SpreadsheetLimits $limits,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function open(string $path, SpreadsheetLimits $limits, string $temporaryBase): self
    {
        if (is_file($path) === false || is_readable($path) === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        $size = (int) filesize($path);

        if ($size > $limits->maxFileBytes) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the file has %d bytes', $size));
        }

        $head = (string) file_get_contents($path, false, null, 0, 4096);

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw SpreadsheetUnreadable::unsupportedFormat('a workbook, not a text file');
        }

        $folder = new TemporaryFolder($temporaryBase);

        try {
            $mark = CsvEncoding::fromByteOrderMark($head);
            $encoding = $mark === null ? CsvEncoding::utf16WithoutMark($head) : $mark[0];
            $offset = $mark === null ? 0 : $mark[1];

            if ($encoding === CsvEncoding::Utf16LittleEndian || $encoding === CsvEncoding::Utf16BigEndian) {
                // Looked through after it became UTF-8: what UTF-16 holds as U+0000 is a control character like any other
                $path = self::rewritten($path, $offset, $encoding, $folder);
                $offset = 0;
                $encoding = CsvEncoding::Utf8;
                self::lookThrough($path, $limits);
            } else {
                [$validUtf8, $bareCarriageReturns, $sample] = self::lookThrough($path, $limits);
                $encoding ??= match (true) {
                    $validUtf8 => CsvEncoding::Utf8,
                    CsvEncoding::looksCentralEuropean($sample) => CsvEncoding::Windows1250,
                    default => CsvEncoding::Windows1252,
                };

                if ($bareCarriageReturns) {
                    $path = self::rewritten($path, $offset, null, $folder);
                    $offset = 0;
                }
            }

            $sample = (string) file_get_contents($path, false, null, $offset, self::SAMPLE_BYTES);
            $delimiter = null;

            // Excel writes (and honours) a first line `sep=;` naming the delimiter
            if (preg_match('/^"?sep=([,;\t|])"?\r?\n/i', $sample, $match) === 1) {
                $delimiter = $match[1];
                $offset += strlen($match[0]);
                $sample = substr($sample, strlen($match[0]));
            }

            $delimiter ??= self::detectDelimiter($sample, $limits->maxRowBytes);
        } catch (\ErrorException $e) {
            $folder->remove();

            // A warning PHP raised over what the file holds (and an error handler made an exception of)
            throw SpreadsheetUnreadable::damaged('the file could not be read', $e);
        } catch (\Throwable $e) {
            $folder->remove();

            throw $e;
        }

        return new self($path, $folder, $encoding, $delimiter, $offset, new ReadSheet(1, ''), $limits);
    }

    public function sheets(): array
    {
        return [$this->sheet];
    }

    public function rows(ReadSheet $sheet, null|CellRange $window = null): \Generator
    {
        if ($this->closed || $sheet !== $this->sheet) {
            throw new \InvalidArgumentException('The sheet does not belong to this file, or the file is closed.');
        }

        return $this->stream($window);
    }

    public function mergedRanges(ReadSheet $sheet): array
    {
        return [];
    }

    public function rowsRead(): int
    {
        return $this->rowsRead;
    }

    public function cellsRead(): int
    {
        return $this->cellsRead;
    }

    public function close(): void
    {
        if ($this->closed === false) {
            $this->closed = true;
            $this->folder->remove();
        }
    }

    /**
     * @return \Generator<int, ReadRow>
     */
    private function stream(null|CellRange $window): \Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        $limits = $this->limits;
        $maxRows = min($limits->maxRows, $limits->maxRowsPerSheet);

        // The parser never reads the file itself but a view of it that is moved along: a record can run over many
        // lines - as far as a quote that never closes takes it - and the view is where that ends
        $recordBytes = max(0, $limits->maxRowBytes) + 2;
        $view = null;
        $viewBytes = 0;
        $exhausted = false;

        try {
            fseek($handle, $this->dataOffset);
            $number = 0;
            $rows = 0;

            while (true) {
                $position = $view === null ? 0 : (int) ftell($view);

                if ($exhausted === false && $viewBytes - $position < $recordBytes) {
                    [$view, $viewBytes, $exhausted] = self::movedWindow($handle, $view, max(65_536, $recordBytes));
                    $position = 0;
                }

                if ($view === null || $position >= $viewBytes) {
                    break;
                }

                // No line is longer than a row may be (lookThrough() saw to that)
                $record = fgetcsv($view, $recordBytes, $this->delimiter, '"', '');

                if ($record === false) {
                    break;
                }

                if ((int) ftell($view) - $position > $recordBytes) {
                    throw SpreadsheetUnreadable::cellTooLarge(sprintf('a record of more than %d bytes', $limits->maxRowBytes));
                }

                $number++;

                // Empty fields count: a line of nothing but delimiters is as wide as it is long
                if (count($record) > $limits->maxColumns) {
                    throw SpreadsheetUnreadable::tooManyColumns($limits->maxColumns);
                }

                if ($window !== null && ($number < $window->firstRow || $number > $window->lastRow)) {
                    if ($number > $window->lastRow) {
                        break;
                    }

                    continue;
                }

                $cells = [];

                foreach ($record as $index => $value) {
                    if ($value === null || $value === '' || ($window !== null && ($index + 1 < $window->firstColumn || $index + 1 > $window->lastColumn))) {
                        continue;
                    }

                    // A quoted cell may run over many lines - as far as an unclosed quote takes it
                    if (strlen($value) > $limits->maxCellBytes) {
                        throw SpreadsheetUnreadable::cellTooLarge(sprintf('a cell of more than %d bytes', $limits->maxCellBytes));
                    }

                    $text = ReadCell::withoutControls($this->encoding->decode($value));

                    // A byte-order mark left inside the text (a file glued together)
                    if ($number === 1 && $index === 0 && str_starts_with($text, "\u{FEFF}")) {
                        $text = substr($text, 3);
                    }

                    if ($text !== '') {
                        $cells[$index + 1] = ReadCell::text($text);
                    }
                }

                if ($cells === []) {
                    continue;
                }

                $this->rowsRead++;
                $this->cellsRead += count($cells);

                if (++$rows > $limits->maxRowsPerSheet || $this->rowsRead > $limits->maxRows) {
                    throw SpreadsheetUnreadable::tooManyRows($maxRows);
                }

                if ($this->cellsRead > $limits->maxCells) {
                    throw SpreadsheetUnreadable::tooManyCells($limits->maxCells);
                }

                yield new ReadRow($number, $cells);
            }
        } catch (\ErrorException $e) {
            // A warning PHP raised over what the file holds (and an error handler made an exception of)
            throw SpreadsheetUnreadable::damaged('the file could not be read', $e);
        } finally {
            fclose($handle);

            if ($view !== null) {
                fclose($view);
            }
        }
    }

    /**
     * The next window on the file: what the parser has not read of the old one, and more of the file behind it - so
     * that a record that starts in it has room for as many bytes as a record may have, and no more than a few times
     * that is ever in memory.
     *
     * @param resource $file
     * @param null|resource $window
     *
     * @return array{resource, int, bool} the window (in memory), its length, and whether the file is read to its end
     */
    private static function movedWindow($file, $window, int $blockBytes): array
    {
        $unread = '';

        if ($window !== null) {
            $unread = (string) stream_get_contents($window);
            fclose($window);
        }

        $moved = fopen('php://memory', 'w+b');

        if ($moved === false) {
            throw new SpreadsheetReaderFailed('A stream in memory can not be opened.');
        }

        fwrite($moved, $unread);
        $added = stream_copy_to_stream($file, $moved, 2 * $blockBytes);

        if ($added === false) {
            fclose($moved);

            throw SpreadsheetUnreadable::damaged('the file can not be read');
        }

        rewind($moved);

        return [$moved, strlen($unread) + $added, $added === 0 || feof($file)];
    }

    /**
     * One pass over the file in chunks: is it text at all, is no line longer than a row may be, is it valid UTF-8,
     * does it end its lines with a bare \r.
     *
     * @return array{bool, bool, string} valid UTF-8?, bare \r line ends?, the first bytes (to tell the code page)
     */
    private static function lookThrough(string $path, SpreadsheetLimits $limits): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        // A chunk no longer than a line may be: a line that is too long then always reaches over a chunk's edge
        $chunkBytes = max(1, min(self::CHUNK_BYTES, $limits->maxRowBytes));

        try {
            $validUtf8 = true;
            $carry = '';
            $controls = 0;
            $size = 0;
            $carriageReturns = false;
            $lineFeeds = false;
            $sample = '';
            $lineBytes = 0;

            while (feof($handle) === false) {
                $chunk = fread($handle, $chunkBytes);

                if ($chunk === false) {
                    throw SpreadsheetUnreadable::damaged('the file can not be read');
                }

                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                // Text holds hardly any control characters, NUL least of all: a picture, an archive, a database do
                $controls += (int) preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $chunk);
                $carriageReturns = $carriageReturns || str_contains($chunk, "\r");
                $lineFeeds = $lineFeeds || str_contains($chunk, "\n");

                $firstBreak = strcspn($chunk, "\r\n");

                if ($firstBreak === strlen($chunk)) {
                    $lineBytes += strlen($chunk);
                } else {
                    $lineBytes += $firstBreak;

                    if ($lineBytes > $limits->maxRowBytes) {
                        break;
                    }

                    $lineBytes = strlen($chunk) - 1 - max((int) strrpos($chunk, "\n"), (int) strrpos($chunk, "\r"));
                }

                if ($lineBytes > $limits->maxRowBytes) {
                    break;
                }

                if (strlen($sample) < self::SAMPLE_BYTES) {
                    $sample .= substr($chunk, 0, self::SAMPLE_BYTES - strlen($sample));
                }

                if ($validUtf8) {
                    $chunk = $carry . $chunk;
                    $whole = strlen($chunk) - self::incompleteTail($chunk);
                    $validUtf8 = mb_check_encoding(substr($chunk, 0, $whole), 'UTF-8');
                    $carry = substr($chunk, $whole);
                }
            }

            if ($controls > max(2, $size / 100)) {
                throw SpreadsheetUnreadable::notASpreadsheet('not a text file');
            }

            if ($lineBytes > $limits->maxRowBytes) {
                throw SpreadsheetUnreadable::cellTooLarge(sprintf('a line of more than %d bytes', $limits->maxRowBytes));
            }

            return [$validUtf8 && $carry === '', $carriageReturns && $lineFeeds === false, $sample];
        } finally {
            fclose($handle);
        }
    }

    /**
     * How many bytes at the end of a chunk start a UTF-8 character that the next chunk finishes.
     */
    private static function incompleteTail(string $chunk): int
    {
        $length = strlen($chunk);

        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($chunk[$length - $back]);

            if ($byte < 0x80) {
                return 0;
            }

            if ($byte >= 0xC0) {
                $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                return $needed > $back ? $back : 0;
            }
        }

        return 0;
    }

    /**
     * The file again as UTF-8 with \n line ends, in the reader's temporary folder.
     *
     * @param null|CsvEncoding $utf16 the UTF-16 flavour to convert from; null = the bytes stay, only line ends change
     */
    private static function rewritten(string $path, int $offset, null|CsvEncoding $utf16, TemporaryFolder $folder): string
    {
        $copy = $folder->newFile('csv');
        $source = @fopen($path, 'rb');
        // "x": created here and now - never a file that is already there
        $target = @fopen($copy, 'xb');

        try {
            if ($source === false) {
                throw SpreadsheetUnreadable::damaged('the file can not be opened');
            }

            if ($target === false) {
                throw new SpreadsheetReaderFailed('The text file can not be rewritten into the temporary folder.');
            }

            fseek($source, $offset);

            if ($utf16 !== null && @stream_filter_append($source, 'convert.iconv.' . $utf16->value . '/UTF-8', STREAM_FILTER_READ) === false) {
                throw new SpreadsheetReaderFailed('The UTF-16 converter is not there.');
            }

            $pendingCarriageReturn = false;

            while (feof($source) === false) {
                $chunk = @fread($source, 65_536);

                if ($chunk === false) {
                    throw SpreadsheetUnreadable::damaged('the file is not UTF-16 text');
                }

                if ($pendingCarriageReturn) {
                    $chunk = "\r" . $chunk;
                }

                $pendingCarriageReturn = str_ends_with($chunk, "\r");

                if ($pendingCarriageReturn) {
                    $chunk = substr($chunk, 0, -1);
                }

                self::write($target, str_replace(["\r\n", "\r"], "\n", $chunk));
            }

            if ($pendingCarriageReturn) {
                self::write($target, "\n");
            }

            // A full disk shows here at the latest - never a copy that is shorter than the file
            if (@fflush($target) === false || @fclose($target) === false) {
                $target = false;

                throw new SpreadsheetReaderFailed('The text file can not be rewritten into the temporary folder.');
            }

            $target = false;
        } finally {
            if ($source !== false) {
                fclose($source);
            }

            if ($target !== false) {
                @fclose($target);
            }
        }

        return $copy;
    }

    /**
     * @param resource $target
     */
    private static function write($target, string $bytes): void
    {
        if ($bytes !== '' && @fwrite($target, $bytes) !== strlen($bytes)) {
            throw new SpreadsheetReaderFailed('The text file can not be rewritten into the temporary folder.');
        }
    }

    /**
     * The delimiter giving the most of the first records the same number of fields (more than one), then the one that
     * gives all of them the same number, then the most fields - as long as at least half of the records agree. A file
     * of one column has no delimiter to find: a comma it is.
     */
    private static function detectDelimiter(string $sample, int $maxLineBytes): string
    {
        $best = ',';
        $bestScore = [0, 0, 0];

        foreach (self::DELIMITERS as $delimiter) {
            $records = self::firstRecords($sample, $delimiter, $maxLineBytes);
            $counts = array_values(array_filter($records, static fn (int $fields): bool => $fields > 1));

            if ($counts === []) {
                continue;
            }

            $frequencies = array_count_values($counts);
            arsort($frequencies);
            $width = (int) array_key_first($frequencies);

            // One cell with a semicolon in a file of one column is no reason: half of the records have to agree
            if ($frequencies[$width] * 2 < count($records)) {
                continue;
            }

            $score = [$frequencies[$width], count($frequencies) === 1 ? 1 : 0, $width];

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<int> the number of fields of each of the first records that hold a value
     */
    private static function firstRecords(string $sample, string $delimiter, int $maxLineBytes): array
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            return [];
        }

        try {
            fwrite($stream, $sample);
            rewind($stream);
            $fields = [];

            while (count($fields) < self::DELIMITER_SAMPLE_RECORDS && ($record = fgetcsv($stream, max(0, $maxLineBytes) + 2, $delimiter, '"', '')) !== false) {
                foreach ($record as $value) {
                    if ($value !== null && trim($value) !== '') {
                        $fields[] = count($record);

                        break;
                    }
                }
            }

            return $fields;
        } finally {
            fclose($stream);
        }
    }
}
