<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantSheet;

/**
 * Reads an uploaded participant list into plain trimmed strings
 * (docs/features/competitions-management/participant-import-preview.md, D1 + D3).
 *
 * - .xlsx: every sheet with at least one value (hidden ones too), values as the organiser sees them in a cell with the
 *   General format (`123`, never `123.0`); a formula gives the value Excel saved with it, nothing is recalculated.
 * - CSV/TSV: delimiter `,` `;` or tab (detected, or Excel's `sep=;` first line), RFC 4180 quotes, UTF-8 with or
 *   without BOM, UTF-16 with BOM (Excel's "Unicode Text"), anything else read as Windows-1252. Values stay text.
 *
 * The first row with a value is the header. Rows are keyed by the row number a spreadsheet shows, empty rows are left
 * out, and every row is as wide as the widest row (headers padded with '' for values beyond the header).
 * Anything that is not a readable file throws ParticipantFileUnreadable - never a 500.
 */
final class ParticipantFileReader
{
    private const array CSV_DELIMITERS = [',', ';', "\t"];
    private const int DELIMITER_SAMPLE_RECORDS = 50;

    /**
     * @return list<string> xlsx: the names of the sheets with a value, in workbook order (sheet indexes refer to this
     *                      list); csv: one unnamed sheet
     */
    public function sheetNames(string $path, ParticipantFileFormat $format): array
    {
        if ($format === ParticipantFileFormat::Csv) {
            if (self::sheet($this->csvRecords($path))->headers === []) {
                throw new ParticipantFileUnreadable('the file has no values');
            }

            return [''];
        }

        return $this->xlsxSheetNames($path);
    }

    public function read(string $path, ParticipantFileFormat $format, int $sheet = 0): ParticipantSheet
    {
        if ($format === ParticipantFileFormat::Csv) {
            if ($sheet !== 0) {
                throw new ParticipantFileUnreadable(sprintf('a CSV file has no sheet %d', $sheet));
            }

            return self::sheet($this->csvRecords($path));
        }

        $names = $this->xlsxSheetNames($path);

        if (isset($names[$sheet]) === false) {
            throw new ParticipantFileUnreadable(sprintf('the workbook has no sheet %d', $sheet));
        }

        return self::sheet($this->xlsxRows($path, $names[$sheet]));
    }

    /**
     * @return list<string>
     */
    private function xlsxSheetNames(string $path): array
    {
        $reader = $this->xlsxReader();

        try {
            if ($reader->canRead($path) === false) {
                throw new ParticipantFileUnreadable('not an .xlsx workbook');
            }

            $names = [];

            foreach ($reader->listWorksheetInfo($path) as $info) {
                if ($info['totalRows'] > 0 && $info['totalColumns'] > 0) {
                    $names[] = $info['worksheetName'];
                }
            }
        } catch (ParticipantFileUnreadable $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Untrusted input: whatever the library trips over (broken zip member, XML it refuses, …) is a bad file
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        }

        if ($names === []) {
            throw new ParticipantFileUnreadable('the workbook has no values');
        }

        return $names;
    }

    /**
     * @return array<int, array<int, string>> row number => column index (0-based) => value
     */
    private function xlsxRows(string $path, string $sheetName): array
    {
        $reader = $this->xlsxReader();
        $reader->setLoadSheetsOnly([$sheetName]);

        try {
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        }

        try {
            $cells = $spreadsheet->getActiveSheet()->getCellCollection();
            $rows = [];

            foreach ($cells->getCoordinates() as $coordinate) {
                $cell = $cells->get($coordinate);

                if ($cell === null) {
                    continue;
                }

                [$column, $row] = Coordinate::indexesFromString($coordinate);
                $rows[$row][$column - 1] = self::cellText($cell);
            }

            ksort($rows);

            return $rows;
        } catch (\Throwable $e) {
            throw new ParticipantFileUnreadable('broken .xlsx workbook', $e);
        } finally {
            $spreadsheet->disconnectWorksheets();
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
     * @return array<int, array<int, string>> row number => column index => value
     */
    private function csvRecords(string $path): array
    {
        $content = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($content === false) {
            throw new ParticipantFileUnreadable('the file can not be opened');
        }

        $content = self::toUtf8($content);

        if (trim($content) === '') {
            throw new ParticipantFileUnreadable('the file is empty');
        }

        // Excel for Mac's "CSV (Macintosh)" ends lines with a bare CR
        if (str_contains($content, "\n") === false) {
            $content = str_replace("\r", "\n", $content);
        }

        $delimiter = null;

        // Excel writes (and honours) a first line `sep=;` naming the delimiter
        if (preg_match('/^"?sep=([^\r\n"])"?\r?\n/i', $content, $match) === 1) {
            $delimiter = $match[1];
            $content = substr($content, strlen($match[0]));
        }

        $delimiter ??= self::detectDelimiter($content);
        $records = self::parseCsv($content, $delimiter, null);

        if ($records === []) {
            throw new ParticipantFileUnreadable('the file has no values');
        }

        return $records;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } elseif (mb_check_encoding($content, 'UTF-8') === false) {
            if (self::looksBinary($content)) {
                throw new ParticipantFileUnreadable('not a text file');
            }

            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if (self::looksBinary($content)) {
            throw new ParticipantFileUnreadable('not a text file');
        }

        return $content;
    }

    /**
     * A zip (an .xlsx renamed to .csv), an image, … - text never holds NUL bytes, and hardly any control characters.
     */
    private static function looksBinary(string $content): bool
    {
        if (str_contains($content, "\0") || str_starts_with($content, "PK\x03\x04")) {
            return true;
        }

        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content);

        return $controls > max(2, strlen($content) / 100);
    }

    /**
     * The delimiter that splits the header into the most columns and the first records into as many.
     */
    private static function detectDelimiter(string $content): string
    {
        $best = self::CSV_DELIMITERS[0];
        $bestScore = [-1, -1];

        foreach (self::CSV_DELIMITERS as $delimiter) {
            $records = array_values(self::parseCsv($content, $delimiter, self::DELIMITER_SAMPLE_RECORDS));

            if ($records === []) {
                continue;
            }

            $width = count($records[0]);
            $agreeing = 0;

            foreach ($records as $record) {
                if (count($record) === $width) {
                    $agreeing++;
                }
            }

            $score = [$width > 1 ? $agreeing : 0, $width];

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array<int, string>> record number (1 = the first line) => cells; records with no value left out
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

                foreach ($record as $index => $value) {
                    $cells[$index] = $value ?? '';
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
     * @param array<int, array<int, string>> $rows row number => column index => raw value
     */
    private static function sheet(array $rows): ParticipantSheet
    {
        $trimmed = [];
        $width = 0;

        foreach ($rows as $number => $cells) {
            $values = [];
            $last = -1;

            foreach ($cells as $column => $value) {
                $value = self::trim($value);
                $values[$column] = $value;

                if ($value !== '' && $column > $last) {
                    $last = $column;
                }
            }

            if ($last === -1) {
                continue;
            }

            $trimmed[$number] = $values;
            $width = max($width, $last + 1);
        }

        if ($trimmed === []) {
            return new ParticipantSheet([], []);
        }

        $padded = [];

        foreach ($trimmed as $number => $values) {
            $row = [];

            for ($column = 0; $column < $width; $column++) {
                $row[] = $values[$column] ?? '';
            }

            $padded[$number] = $row;
        }

        $headerRow = array_key_first($padded);
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
