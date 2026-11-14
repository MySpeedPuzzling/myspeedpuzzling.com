<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Csv;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * An opened CSV/TSV text file: one sheet, every cell a text exactly as written (typing "0:56:4" or "12.03.2024" is
 * the next layer's job). The file is never loaded whole - it is looked through once in chunks (encoding, binary
 * check), then parsed record by record.
 *
 * - Encoding: a byte-order mark, UTF-16 without one, valid UTF-8, else Windows-1250 or Windows-1252 (CsvEncoding).
 * - Delimiter: Excel's `sep=;` first line, else the one of `,` `;` tab that gives the first records the same number
 *   of fields - never the most frequent character (a `;` file often holds "Solo, Pair" in a cell).
 * - Quotes as in RFC 4180 (a cell may hold delimiters, quotes and line breaks); lines end with \r\n, \n or a bare \r.
 * - A row's number is the record's number as a spreadsheet shows the file (a `sep=` line is not a row; a record with
 *   line breaks inside a cell is one row); a record without a value is not handed out.
 *
 * UTF-16 files and files with bare \r line ends are first rewritten as UTF-8 with \n into the temporary folder -
 * close() removes the copy.
 */
final class CsvWorkbook implements ReadWorkbook
{
    private const int SAMPLE_BYTES = 262_144;
    private const int CHUNK_BYTES = 1_048_576;
    private const int DELIMITER_SAMPLE_RECORDS = 20;
    private const array DELIMITERS = [',', ';', "\t"];

    private bool $closed = false;

    private function __construct(
        /** The file that is parsed: the upload itself, or its rewritten copy */
        private readonly string $path,
        private readonly null|string $temporaryCopy,
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

        $mark = CsvEncoding::fromByteOrderMark($head);
        $encoding = $mark === null ? CsvEncoding::utf16WithoutMark($head) : $mark[0];
        $offset = $mark === null ? 0 : $mark[1];
        $utf16 = $encoding === CsvEncoding::Utf16LittleEndian || $encoding === CsvEncoding::Utf16BigEndian;
        $bareCarriageReturns = false;

        if ($utf16 === false) {
            [$validUtf8, $bareCarriageReturns, $sample] = self::lookThrough($path, $size);
            $encoding ??= match (true) {
                $validUtf8 => CsvEncoding::Utf8,
                CsvEncoding::looksCentralEuropean($sample) => CsvEncoding::Windows1250,
                default => CsvEncoding::Windows1252,
            };
        }

        $copy = null;

        if ($utf16 || $bareCarriageReturns) {
            $copy = self::rewritten($path, $offset, $utf16 ? $encoding : null, $temporaryBase);
            $path = $copy;
            $offset = 0;
            $encoding = $utf16 ? CsvEncoding::Utf8 : $encoding;
        }

        try {
            $sample = (string) file_get_contents($path, false, null, $offset, self::SAMPLE_BYTES);
            $delimiter = null;

            // Excel writes (and honours) a first line `sep=;` naming the delimiter
            if (preg_match('/^"?sep=([,;\t|])"?\r?\n/i', $sample, $match) === 1) {
                $delimiter = $match[1];
                $offset += strlen($match[0]);
                $sample = substr($sample, strlen($match[0]));
            }

            $delimiter ??= self::detectDelimiter($sample);
        } catch (\Throwable $e) {
            if ($copy !== null) {
                @unlink($copy);
            }

            throw $e;
        }

        return new self($path, $copy, $encoding, $delimiter, $offset, new ReadSheet(1, ''), $limits);
    }

    public function sheets(): array
    {
        return [$this->sheet];
    }

    public function rows(ReadSheet $sheet): \Generator
    {
        if ($this->closed || $sheet !== $this->sheet) {
            throw new \InvalidArgumentException('The sheet does not belong to this file, or the file is closed.');
        }

        return $this->stream();
    }

    public function mergedRanges(ReadSheet $sheet): array
    {
        return [];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->temporaryCopy !== null) {
            @unlink($this->temporaryCopy);
        }
    }

    /**
     * @return \Generator<int, ReadRow>
     */
    private function stream(): \Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        try {
            fseek($handle, $this->dataOffset);
            $number = 0;
            $rows = 0;
            $cellCount = 0;

            while (($record = fgetcsv($handle, null, $this->delimiter, '"', '')) !== false) {
                $number++;
                $cells = [];

                foreach ($record as $index => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    $text = $this->encoding->decode($value);

                    // A byte-order mark left inside the text (a file glued together)
                    if ($number === 1 && $index === 0 && str_starts_with($text, "\u{FEFF}")) {
                        $text = substr($text, 3);
                    }

                    if ($text === '') {
                        continue;
                    }

                    if ($index >= $this->limits->maxColumns) {
                        throw SpreadsheetUnreadable::tooManyColumns($this->limits->maxColumns);
                    }

                    $cells[$index + 1] = ReadCell::text($text);
                }

                if ($cells === []) {
                    continue;
                }

                $cellCount += count($cells);

                if (++$rows > min($this->limits->maxRows, $this->limits->maxRowsPerSheet)) {
                    throw SpreadsheetUnreadable::tooManyRows(min($this->limits->maxRows, $this->limits->maxRowsPerSheet));
                }

                if ($cellCount > $this->limits->maxCells) {
                    throw SpreadsheetUnreadable::tooManyCells($this->limits->maxCells);
                }

                yield new ReadRow($number, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * One pass over the file in chunks: is it text at all, is it valid UTF-8, does it end its lines with a bare \r.
     *
     * @return array{bool, bool, string} valid UTF-8?, bare \r line ends?, the first bytes (to tell the code page)
     */
    private static function lookThrough(string $path, int $size): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        try {
            $validUtf8 = true;
            $carry = '';
            $controls = 0;
            $carriageReturns = false;
            $lineFeeds = false;
            $sample = '';

            while (feof($handle) === false) {
                $chunk = fread($handle, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw SpreadsheetUnreadable::damaged('the file can not be read');
                }

                // Text never holds NUL bytes, and hardly any control characters: a picture, an archive, a database
                if (str_contains($chunk, "\0")) {
                    throw SpreadsheetUnreadable::notASpreadsheet('not a text file');
                }

                $controls += (int) preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $chunk);
                $carriageReturns = $carriageReturns || str_contains($chunk, "\r");
                $lineFeeds = $lineFeeds || str_contains($chunk, "\n");

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
     * The file again as UTF-8 with \n line ends, in the temporary folder.
     *
     * @param null|CsvEncoding $utf16 the UTF-16 flavour to convert from; null = the bytes stay, only line ends change
     */
    private static function rewritten(string $path, int $offset, null|CsvEncoding $utf16, string $temporaryBase): string
    {
        $copy = rtrim($temporaryBase, '/') . '/msp-spreadsheet-' . bin2hex(random_bytes(8)) . '.csv';
        $source = fopen($path, 'rb');
        $target = fopen($copy, 'xb');

        try {
            if ($source === false || $target === false) {
                throw SpreadsheetUnreadable::damaged('the file can not be rewritten');
            }

            fseek($source, $offset);

            if ($utf16 !== null && stream_filter_append($source, 'convert.iconv.' . $utf16->value . '/UTF-8', STREAM_FILTER_READ) === false) {
                throw SpreadsheetUnreadable::damaged('the file is not UTF-16 text');
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

                fwrite($target, str_replace(["\r\n", "\r"], "\n", $chunk));
            }

            if ($pendingCarriageReturn) {
                fwrite($target, "\n");
            }
        } catch (\Throwable $e) {
            if ($target !== false) {
                fclose($target);
                $target = false;
            }

            @unlink($copy);

            throw $e;
        } finally {
            if ($source !== false) {
                fclose($source);
            }

            if ($target !== false) {
                fclose($target);
            }
        }

        return $copy;
    }

    /**
     * The delimiter giving the most of the first records the same number of fields (more than one), then the one that
     * gives all of them the same number, then the most fields - as long as at least half of the records agree. A file
     * of one column has no delimiter to find: a comma it is.
     */
    private static function detectDelimiter(string $sample): string
    {
        $best = ',';
        $bestScore = [0, 0, 0];

        foreach (self::DELIMITERS as $delimiter) {
            $records = self::firstRecords($sample, $delimiter);
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
    private static function firstRecords(string $sample, string $delimiter): array
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            return [];
        }

        try {
            fwrite($stream, $sample);
            rewind($stream);
            $fields = [];

            while (count($fields) < self::DELIMITER_SAMPLE_RECORDS && ($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if (array_filter($record, static fn (null|string $value): bool => $value !== null && trim($value) !== '') !== []) {
                    $fields[] = count($record);
                }
            }

            return $fields;
        } finally {
            fclose($stream);
        }
    }
}
