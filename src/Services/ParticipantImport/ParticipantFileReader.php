<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantFileSheetInfo;
use SpeedPuzzling\Web\Value\ParticipantSheet;

/**
 * Reads an uploaded participant list into plain trimmed strings
 * (docs/features/competitions-management/participant-import-preview.md, D1 + D3).
 *
 * - .xlsx: every sheet with at least one value (hidden ones flagged), values as the organiser sees them in a cell with
 *   the General format (`123`, never `123.0`); a formula gives the value Excel saved with it, nothing is recalculated.
 *   A merged range gives its top-left value to every cell of the range (a team name merged over 4 rows).
 * - CSV/TSV/TXT: delimiter `,` `;` or tab - detected by a field count that stays the same over the first records, or
 *   Excel's `sep=;` first line; RFC 4180 quotes; encoding by BOM (UTF-8, UTF-16), then valid UTF-8, then Windows-1250
 *   when the bytes look Central European, otherwise Windows-1252. ParticipantFileOptions overrides both.
 *
 * The header is the first row with at least 2 values (title rows above it are left out). Rows are keyed by the row number
 * a spreadsheet shows, empty rows are left out, every row is as wide as the widest row (headers padded with ''). At most
 * MAX_ROWS rows × MAX_COLUMNS columns are read; a workbook unpacking to more than MAX_UNPACKED_BYTES (or one part to
 * more than MAX_UNPACKED_PART_BYTES) or holding more than MAX_CELLS cells in the sheet is refused. Anything that is not
 * a readable file throws ParticipantFileUnreadable - never a 500.
 */
final class ParticipantFileReader
{
    public const int MAX_ROWS = 5000;
    public const int MAX_COLUMNS = 40;
    /** Cells of one sheet read from a workbook (MAX_ROWS × MAX_COLUMNS would be twice as many) */
    public const int MAX_CELLS = 100_000;
    /** Merged ranges of one sheet taken into account */
    public const int MAX_MERGED_RANGES = 5000;
    /** An .xlsx unpacked: all parts together, and any single part (a zip bomb is refused before it is opened) */
    public const int MAX_UNPACKED_BYTES = 50 * 1024 * 1024;
    public const int MAX_UNPACKED_PART_BYTES = 20 * 1024 * 1024;
    private const int MAX_ZIP_ENTRIES = 10_000;
    private const int DELIMITER_SAMPLE_RECORDS = 20;
    /** Bytes of a file looked at to tell Windows-1250 from Windows-1252 */
    private const int ENCODING_SAMPLE_BYTES = 256 * 1024;

    /**
     * Windows-1250 bytes 0x80-0xFF as Unicode code points (mbstring does not know the code page, glibc iconv does but
     * is not everywhere). FFFD = not defined.
     */
    private const string WINDOWS_1250 = '20AC FFFD 201A FFFD 201E 2026 2020 2021 FFFD 2030 160 2039 15A 164 17D 179 '
        . 'FFFD 2018 2019 201C 201D 2022 2013 2014 FFFD 2122 161 203A 15B 165 17E 17A '
        . 'A0 2C7 2D8 141 A4 104 A6 A7 A8 A9 15E AB AC AD AE 17B B0 B1 2DB 142 B4 B5 B6 B7 B8 105 15F BB 13D 2DD 13E 17C '
        . '154 C1 C2 102 C4 139 106 C7 10C C9 118 CB 11A CD CE 10E 110 143 147 D3 D4 150 D6 D7 158 16E DA 170 DC DD 162 DF '
        . '155 E1 E2 103 E4 13A 107 E7 10D E9 119 EB 11B ED EE 10F 111 144 148 F3 F4 151 F6 F7 159 16F FA 171 FC FD 163 2D9';

    /**
     * Bytes that are a Central European letter in Windows-1250 (Š Ś Ť Ž Ź š ś ť ž ź Ł Ą ł ą Ľ ľ) and rare in Western
     * text (Œ œ Ÿ £ ¥ ³ ¹ ¼ ¾ or nothing in Windows-1252) - evidence only next to a letter (£15 is a price).
     */
    private const array CENTRAL_EUROPEAN_BYTES = [
        0x8A, 0x8C, 0x8D, 0x8E, 0x8F, 0x9A, 0x9C, 0x9D, 0x9E, 0x9F, 0xA3, 0xA5, 0xB3, 0xB9, 0xBC, 0xBE,
    ];

    /**
     * Bytes that are a consonant in Windows-1250 (Č č Ň ň Ř ř) and a vowel in Windows-1252 (È è Ò ò Ø ø): Czech has
     * them next to vowels (Jiří, Černý, Dvořák), Western text between consonants (Hélène, Søren, Niccolò).
     */
    private const array CONSONANT_OR_VOWEL_BYTES = [0xC8, 0xE8, 0xD2, 0xF2, 0xD8, 0xF8];

    /**
     * Ě ě in Windows-1250, Ì ì in Windows-1252: ě sits inside a word (Věra, Zbyněk), ì mostly ends one (così).
     */
    private const array E_CARON_BYTES = [0xCC, 0xEC];

    /**
     * Bytes that are a Western letter in Windows-1252 (À Ã Å Æ Ñ Õ à ã å æ ê ñ õ û) and a rare letter in Windows-1250.
     */
    private const array WESTERN_BYTES = [0xC0, 0xC3, 0xC5, 0xC6, 0xD1, 0xD5, 0xE0, 0xE3, 0xE5, 0xE6, 0xEA, 0xF1, 0xF5, 0xFB];

    /**
     * Vowels that are the same letter in both code pages (Á É Í Ó Ú Ý Ä Ö Ü Â Ô Ë Î and lower case).
     */
    private const array SHARED_VOWEL_BYTES = [
        0xC1, 0xC9, 0xCD, 0xD3, 0xDA, 0xDD, 0xE1, 0xE9, 0xED, 0xF3, 0xFA, 0xFD,
        0xC4, 0xD6, 0xDC, 0xE4, 0xF6, 0xFC, 0xC2, 0xD4, 0xE2, 0xF4, 0xCB, 0xEB, 0xCE, 0xEE,
    ];

    /**
     * @return list<ParticipantFileSheetInfo> xlsx: the sheets with a value, in workbook order (read() takes an index of
     *                                        this list); csv: one sheet named ''
     */
    public function sheets(string $path, ParticipantFileFormat $format): array
    {
        if ($format === ParticipantFileFormat::Csv) {
            [$records] = $this->csv($path, new ParticipantFileOptions());
            $last = 0;

            foreach ($records as $number => $cells) {
                if (self::hasValue($cells)) {
                    $last = $number;
                }
            }

            if ($last === 0) {
                throw ParticipantFileUnreadable::empty();
            }

            return [new ParticipantFileSheetInfo(0, '', false, $last)];
        }

        return $this->xlsxSheets($path);
    }

    public function read(
        string $path,
        ParticipantFileFormat $format,
        int $sheet = 0,
        ParticipantFileOptions $options = new ParticipantFileOptions(),
    ): ParticipantSheet {
        if ($format === ParticipantFileFormat::Csv) {
            if ($sheet !== 0) {
                throw new ParticipantFileUnreadable(sprintf('a CSV file has no sheet %d', $sheet));
            }

            [$records] = $this->csv($path, $options);

            return self::sheet($records, []);
        }

        $sheets = $this->xlsxSheets($path);
        $info = $sheets[$sheet] ?? throw new ParticipantFileUnreadable(sprintf('the workbook has no sheet %d', $sheet));

        if ($info->rows > self::MAX_ROWS) {
            throw ParticipantFileUnreadable::tooManyRows(self::MAX_ROWS);
        }

        return self::sheet($this->xlsxRows($path, $info->name), $this->xlsxMergedRanges($path, $info->name));
    }

    /**
     * What "Automatic" picks for a CSV: the concrete encoding and separator (never AUTO).
     */
    public function detectCsvOptions(string $path): ParticipantFileOptions
    {
        [, $detected] = $this->csv($path, new ParticipantFileOptions());

        return $detected;
    }

    /**
     * @return list<ParticipantFileSheetInfo>
     */
    private function xlsxSheets(string $path): array
    {
        self::assertUnpackedSizeIsSafe($path);

        $reader = $this->xlsxReader();

        try {
            if ($reader->canRead($path) === false) {
                throw new ParticipantFileUnreadable('not an .xlsx workbook');
            }

            $sheets = [];

            foreach ($reader->listWorksheetInfo($path) as $info) {
                if ($info['totalRows'] > 0 && $info['totalColumns'] > 0) {
                    $sheets[] = new ParticipantFileSheetInfo(
                        index: count($sheets),
                        name: $info['worksheetName'],
                        hidden: $info['sheetState'] !== Worksheet::SHEETSTATE_VISIBLE,
                        rows: $info['totalRows'],
                    );
                }
            }
        } catch (ParticipantFileUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Untrusted input: whatever the library trips over (broken zip member, XML it refuses, …) is a bad file
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        }

        if ($sheets === []) {
            throw ParticipantFileUnreadable::empty();
        }

        return $sheets;
    }

    /**
     * @return array<int, array<int, string>> row number => column index (0-based) => value
     */
    private function xlsxRows(string $path, string $sheetName): array
    {
        $reader = $this->xlsxReader();
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new class (self::MAX_ROWS, self::MAX_COLUMNS, self::MAX_CELLS) implements IReadFilter {
            private int $cells = 0;

            public function __construct(
                private readonly int $maxRows,
                private readonly int $maxColumns,
                private readonly int $maxCells,
            ) {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                if ($row > $this->maxRows || Coordinate::columnIndexFromString($columnAddress) > $this->maxColumns) {
                    return false;
                }

                // Memory: every accepted cell becomes an object
                if (++$this->cells > $this->maxCells) {
                    throw ParticipantFileUnreadable::tooLarge(sprintf('more than %d cells', $this->maxCells));
                }

                return true;
            }
        });

        try {
            $spreadsheet = $reader->load($path);
        } catch (ParticipantFileUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        }

        try {
            $worksheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
            $cells = $worksheet->getCellCollection();
            $rows = [];

            foreach ($cells->getCoordinates() as $coordinate) {
                $cell = $cells->get($coordinate);

                if ($cell === null) {
                    continue;
                }

                [$column, $row] = Coordinate::indexesFromString($coordinate);

                if ($row <= self::MAX_ROWS && $column <= self::MAX_COLUMNS) {
                    $rows[$row][$column - 1] = self::cellText($cell);
                }
            }

            ksort($rows);

            return $rows;
        } catch (\Throwable $e) {
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * A 5 MB upload may unpack to gigabytes (a zip bomb): the sizes the archive declares are checked before
     * PhpSpreadsheet reads a byte of it. A file that is no zip at all is left to the reader's own check.
     */
    private static function assertUnpackedSizeIsSafe(string $path): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return;
        }

        try {
            if ($zip->count() > self::MAX_ZIP_ENTRIES) {
                throw ParticipantFileUnreadable::tooLarge(sprintf('%d zip entries', $zip->count()));
            }

            $total = 0;

            for ($index = 0; $index < $zip->count(); $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw new ParticipantFileUnreadable('a broken zip entry');
                }

                if ($stat['size'] > self::MAX_UNPACKED_PART_BYTES) {
                    throw ParticipantFileUnreadable::tooLarge(sprintf('the zip entry "%s" unpacks to %d bytes', $stat['name'], $stat['size']));
                }

                $total += $stat['size'];

                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw ParticipantFileUnreadable::tooLarge(sprintf('the workbook unpacks to more than %d bytes', self::MAX_UNPACKED_BYTES));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function xlsxReader(): Xlsx
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);

        return $reader;
    }

    /**
     * The merged ranges of a sheet. PhpSpreadsheet reads them only together with every style of the workbook
     * (readDataOnly = false), so they come straight from the sheet's XML - the workbook already loaded fine, so its
     * parts passed PhpSpreadsheet's XML security scan. A merge is a nicety: anything odd = no merges.
     *
     * @return list<array{int, int, int, int}> first column (1-based), first row, last column, last row
     */
    private function xlsxMergedRanges(string $path, string $sheetName): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return [];
        }

        try {
            $sheetPath = self::xlsxSheetPath($zip, $sheetName);
        } catch (\Throwable) {
            $sheetPath = null;
        } finally {
            $zip->close();
        }

        if ($sheetPath === null) {
            return [];
        }

        $ranges = [];
        $xml = new \XMLReader();

        try {
            if (@$xml->open('zip://' . $path . '#' . $sheetPath, null, LIBXML_NONET) === false) {
                return [];
            }

            while (@$xml->read()) {
                if ($xml->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($xml->localName === 'sheetData') {
                    // Thousands of cells nobody needs here
                    @$xml->next();

                    continue;
                }

                if ($xml->localName === 'mergeCell') {
                    // Each range is walked over the rows later - a list never has thousands
                    if (count($ranges) >= self::MAX_MERGED_RANGES) {
                        break;
                    }

                    $reference = (string) $xml->getAttribute('ref');

                    if (preg_match('/^\$?[A-Z]{1,3}\$?\d+:\$?[A-Z]{1,3}\$?\d+$/i', $reference) === 1) {
                        [[$firstColumn, $firstRow], [$lastColumn, $lastRow]] = Coordinate::rangeBoundaries($reference);
                        $ranges[] = [$firstColumn, (int) $firstRow, $lastColumn, (int) $lastRow];
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        } finally {
            $xml->close();
        }

        return $ranges;
    }

    /**
     * The zip entry of a sheet: _rels/.rels → workbook → its rels → the sheet's target.
     */
    private static function xlsxSheetPath(\ZipArchive $zip, string $sheetName): null|string
    {
        $workbookPath = null;

        foreach (self::xmlElements($zip, '_rels/.rels', 'Relationship') as $relationship) {
            if (str_ends_with((string) $relationship['Type'], '/officeDocument')) {
                $workbookPath = ltrim((string) $relationship['Target'], '/');

                break;
            }
        }

        if ($workbookPath === null) {
            return null;
        }

        $relationshipId = null;

        foreach (self::xmlElements($zip, $workbookPath, 'sheet') as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            foreach (['http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'http://purl.oclc.org/ooxml/officeDocument/relationships'] as $namespace) {
                $id = (string) $sheet->attributes($namespace)['id'];

                if ($id !== '') {
                    $relationshipId = $id;
                }
            }
        }

        if ($relationshipId === null) {
            return null;
        }

        $directory = dirname($workbookPath);
        $relationshipsPath = ($directory === '.' ? '' : $directory . '/') . '_rels/' . basename($workbookPath) . '.rels';

        foreach (self::xmlElements($zip, $relationshipsPath, 'Relationship') as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = (string) $relationship['Target'];

            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }

            return self::normalizeZipPath(($directory === '.' ? '' : $directory . '/') . $target);
        }

        return null;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function xmlElements(\ZipArchive $zip, string $entry, string $localName): array
    {
        $content = $zip->getFromName($entry);

        if ($content === false || stripos($content, '<!DOCTYPE') !== false) {
            return [];
        }

        $xml = @simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            return [];
        }

        $elements = $xml->xpath(sprintf('//*[local-name()="%s"]', $localName));

        return is_array($elements) ? array_values($elements) : [];
    }

    private static function normalizeZipPath(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private static function cellText(Cell $cell): string
    {
        $value = $cell->getDataType() === DataType::TYPE_FORMULA
            ? $cell->getOldCalculatedValue()
            : $cell->getValue();

        return match (true) {
            $value === null => '',
            $value instanceof RichText => $value->getPlainText(),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => self::numberText($value),
            is_string($value) => $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }

    /**
     * A number as Excel's General format shows it: `123`, `1.5` - a whole number never gets a decimal point.
     */
    private static function numberText(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return number_format($value, 0, '', '');
        }

        return (string) $value;
    }

    /**
     * @return array{array<int, array<int, string>>, ParticipantFileOptions} record number => cells, and the encoding +
     *                                                                       separator actually used
     */
    private function csv(string $path, ParticipantFileOptions $options): array
    {
        $bytes = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($bytes === false) {
            throw new ParticipantFileUnreadable('the file can not be opened');
        }

        if (str_starts_with($bytes, "PK\x03\x04")) {
            throw new ParticipantFileUnreadable('a zip archive (an .xlsx saved as .csv?), not text');
        }

        [$content, $encoding] = self::decode($bytes, $options->encoding);

        if (self::looksBinary($content)) {
            throw new ParticipantFileUnreadable('not a text file');
        }

        // A BOM left inside the text (a file glued together, or one read with a forced encoding)
        $content = (string) preg_replace('/^\x{FEFF}/u', '', $content);
        // Excel for Mac's "CSV (Macintosh)" ends lines with a bare CR
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (trim($content) === '') {
            throw ParticipantFileUnreadable::empty();
        }

        $separator = null;

        // Excel writes (and honours) a first line `sep=;` naming the delimiter
        if (preg_match('/^"?sep=([,;\t|])"?\n/i', $content, $match) === 1) {
            $separator = array_search($match[1], ParticipantFileOptions::SEPARATORS, true);
            $content = substr($content, strlen($match[0]));
        }

        if ($options->separator !== ParticipantFileOptions::AUTO && isset(ParticipantFileOptions::SEPARATORS[$options->separator])) {
            $separator = $options->separator;
        }

        if (is_string($separator) === false) {
            $separator = self::detectSeparator($content);
        }

        $records = self::parseCsv($content, ParticipantFileOptions::SEPARATORS[$separator], null);

        return [$records, new ParticipantFileOptions($encoding, $separator)];
    }

    /**
     * @return array{string, string} UTF-8 text and the encoding it was read as (one of ParticipantFileOptions::ENCODINGS)
     */
    private static function decode(string $bytes, string $encoding): array
    {
        $bom = match (true) {
            str_starts_with($bytes, "\xEF\xBB\xBF") => 'UTF-8',
            str_starts_with($bytes, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($bytes, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };

        if ($encoding === ParticipantFileOptions::AUTO || in_array($encoding, ParticipantFileOptions::ENCODINGS, true) === false) {
            $encoding = match (true) {
                $bom === 'UTF-8' => 'UTF-8',
                $bom !== null, self::utf16WithoutBom($bytes) !== null => 'UTF-16',
                mb_check_encoding($bytes, 'UTF-8') => 'UTF-8',
                self::looksCentralEuropean($bytes) => 'Windows-1250',
                default => 'Windows-1252',
            };
        }

        $text = match ($encoding) {
            'UTF-8' => mb_scrub($bom === 'UTF-8' ? substr($bytes, 3) : $bytes, 'UTF-8'),
            'UTF-16' => match ($bom) {
                'UTF-16LE' => self::convert(substr($bytes, 2), 'UTF-16LE'),
                'UTF-16BE' => self::convert(substr($bytes, 2), 'UTF-16BE'),
                default => self::convert($bytes, self::utf16WithoutBom($bytes) ?? 'UTF-16LE'),
            },
            'Windows-1250' => self::fromWindows1250($bytes),
            default => self::convert($bytes, 'Windows-1252'),
        };

        return [$text, $encoding];
    }

    private static function convert(string $bytes, string $from): string
    {
        $text = mb_convert_encoding($bytes, 'UTF-8', $from);

        if ($text === false) {
            throw new ParticipantFileUnreadable(sprintf('not %s text', $from));
        }

        return $text;
    }

    /**
     * UTF-16 written without a BOM: ASCII text has a NUL byte next to every character.
     */
    private static function utf16WithoutBom(string $bytes): null|string
    {
        $sample = substr($bytes, 0, 400);
        $length = strlen($sample) - strlen($sample) % 2;

        if ($length < 4) {
            return null;
        }

        $evenNul = 0;
        $oddNul = 0;

        for ($i = 0; $i < $length; $i += 2) {
            $evenNul += $sample[$i] === "\0" ? 1 : 0;
            $oddNul += $sample[$i + 1] === "\0" ? 1 : 0;
        }

        $pairs = $length / 2;

        return match (true) {
            $oddNul >= $pairs * 0.4 && $evenNul === 0 => 'UTF-16LE',
            $evenNul >= $pairs * 0.4 && $oddNul === 0 => 'UTF-16BE',
            default => null,
        };
    }

    /**
     * Windows-1250 only with Central European evidence, and more of it than of Western text: most bytes 0x80-0xFF are
     * a letter in both code pages, so a byte counts by what stands next to it (see the byte lists above).
     */
    private static function looksCentralEuropean(string $bytes): bool
    {
        $sample = substr($bytes, 0, self::ENCODING_SAMPLE_BYTES);
        $length = strlen($sample);
        $central = 0;
        $western = 0;

        if (preg_match_all('/[\x80-\xFF]/', $sample, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        foreach ($matches[0] as [$character, $offset]) {
            $byte = ord($character);
            $before = $offset > 0 ? ord($sample[$offset - 1]) : null;
            $after = $offset + 1 < $length ? ord($sample[$offset + 1]) : null;

            if (in_array($byte, self::CENTRAL_EUROPEAN_BYTES, true)) {
                if (self::isLetterByte($before) || self::isLetterByte($after)) {
                    $central++;
                }
            } elseif (in_array($byte, self::WESTERN_BYTES, true)) {
                $western++;
            } elseif (in_array($byte, self::CONSONANT_OR_VOWEL_BYTES, true)) {
                if (self::isVowelByte($before) || self::isVowelByte($after)) {
                    $central++;
                } else {
                    $western++;
                }
            } elseif (in_array($byte, self::E_CARON_BYTES, true)) {
                if (self::isLetterByte($after)) {
                    $central++;
                } else {
                    $western++;
                }
            }
        }

        return $central >= 1 && $central > $western;
    }

    private static function isVowelByte(null|int $byte): bool
    {
        return $byte !== null
            && (in_array($byte, [0x41, 0x45, 0x49, 0x4F, 0x55, 0x59, 0x61, 0x65, 0x69, 0x6F, 0x75, 0x79], true)
                || in_array($byte, self::SHARED_VOWEL_BYTES, true));
    }

    /**
     * A letter in both code pages (or in one of them): A-Z, a-z, 0x8A-0x9F letters, 0xC0-0xFF but × and ÷.
     */
    private static function isLetterByte(null|int $byte): bool
    {
        return $byte !== null && (
            ($byte >= 0x41 && $byte <= 0x5A)
            || ($byte >= 0x61 && $byte <= 0x7A)
            || in_array($byte, [0x8A, 0x8C, 0x8D, 0x8E, 0x8F, 0x9A, 0x9C, 0x9D, 0x9E, 0x9F], true)
            || ($byte >= 0xC0 && $byte !== 0xD7 && $byte !== 0xF7)
        );
    }

    private static function fromWindows1250(string $bytes): string
    {
        $codePoints = explode(' ', self::WINDOWS_1250);
        $map = [];

        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            $map[chr($byte)] = mb_chr((int) hexdec($codePoints[$byte - 0x80] ?? 'FFFD'), 'UTF-8');
        }

        return strtr($bytes, $map);
    }

    /**
     * A zip, an image, … - text never holds NUL bytes, and hardly any control characters.
     */
    private static function looksBinary(string $content): bool
    {
        if (str_contains($content, "\0")) {
            return true;
        }

        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content);

        return $controls === false || $controls > max(2, strlen($content) / 100);
    }

    /**
     * The separator giving the most of the first records the same number of fields (more than one), then the one that
     * gives all of them the same number, then the most fields - never the most frequent character: a `;` file often
     * holds "Solo, Pair" in a cell. Records with a single field (a title line) do not count against a separator.
     *
     * @return string a key of ParticipantFileOptions::SEPARATORS
     */
    private static function detectSeparator(string $content): string
    {
        $best = 'comma';
        $bestScore = [0, 0, 0];

        foreach (ParticipantFileOptions::SEPARATORS as $key => $separator) {
            $counts = [];

            foreach (self::parseCsv($content, $separator, self::DELIMITER_SAMPLE_RECORDS) as $cells) {
                if (count($cells) > 1) {
                    $counts[] = count($cells);
                }
            }

            if ($counts === []) {
                continue;
            }

            $frequencies = array_count_values($counts);
            arsort($frequencies);
            $width = (int) array_key_first($frequencies);
            $agreeing = $frequencies[$width];
            $consistent = count($frequencies) === 1 ? 1 : 0;

            $score = [$agreeing, $consistent, $width];

            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array<int, string>> record number (1 = the first record) => cells; records with no value left
     *                                        out, at most MAX_COLUMNS cells each
     */
    private static function parseCsv(string $content, string $delimiter, null|int $limit): array
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new ParticipantFileUnreadable('no temporary stream');
        }

        try {
            fwrite($stream, $content);
            rewind($stream);

            $records = [];
            $number = 0;

            while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $number++;
                $cells = [];

                foreach (array_slice($record, 0, self::MAX_COLUMNS) as $index => $value) {
                    $cells[$index] = $value ?? '';
                }

                if (self::hasValue($cells) === false) {
                    continue;
                }

                if ($number > self::MAX_ROWS) {
                    throw ParticipantFileUnreadable::tooManyRows(self::MAX_ROWS);
                }

                $records[$number] = $cells;

                if ($limit !== null && count($records) >= $limit) {
                    break;
                }
            }

            return $records;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<int, string> $cells
     */
    private static function hasValue(array $cells): bool
    {
        foreach ($cells as $value) {
            if (self::trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, string>> $rows row number => column index => raw value
     * @param list<array{int, int, int, int}> $mergedRanges first column (1-based), first row, last column, last row
     */
    private static function sheet(array $rows, array $mergedRanges): ParticipantSheet
    {
        $values = [];

        foreach ($rows as $number => $cells) {
            foreach ($cells as $column => $value) {
                $value = self::trim($value);

                if ($value !== '') {
                    $values[$number][$column] = $value;
                }
            }
        }

        ksort($values);

        // The first row with at least 2 values, so a title above the table is not taken for the header
        $headerRow = null;

        foreach ($values as $number => $cells) {
            if (count($cells) >= 2) {
                $headerRow = $number;

                break;
            }
        }

        $headerRow ??= array_key_first($values);

        if ($headerRow === null) {
            throw ParticipantFileUnreadable::empty();
        }

        // Merged after the header is found: a title merged over the whole width is still one value
        foreach ($mergedRanges as [$firstColumn, $firstRow, $lastColumn, $lastRow]) {
            $value = $values[$firstRow][$firstColumn - 1] ?? null;

            if ($value === null) {
                continue;
            }

            // Only rows that have a value anyway: a merge reaching below the list does not make rows up
            foreach (array_keys($values) as $number) {
                if ($number < $firstRow || $number > $lastRow) {
                    continue;
                }

                for ($column = $firstColumn - 1; $column < min($lastColumn, self::MAX_COLUMNS); $column++) {
                    $values[$number][$column] ??= $value;
                }
            }
        }

        $width = 0;

        foreach ($values as $number => $cells) {
            if ($number >= $headerRow) {
                $width = max($width, max(array_keys($cells)) + 1);
            }
        }

        $padded = [];

        foreach ($values as $number => $cells) {
            if ($number < $headerRow) {
                continue;
            }

            $row = [];

            for ($column = 0; $column < $width; $column++) {
                $row[] = $cells[$column] ?? '';
            }

            $padded[$number] = $row;
        }

        $headers = $padded[$headerRow];
        unset($padded[$headerRow]);

        return new ParticipantSheet($headers, $padded);
    }

    private static function trim(string $value): string
    {
        // Spreadsheets love non-breaking spaces; a BOM may sit on the first cell of a file written by hand
        return preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value) ?? trim($value);
    }
}
