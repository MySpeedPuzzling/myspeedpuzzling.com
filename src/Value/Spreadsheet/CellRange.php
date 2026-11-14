<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * A rectangle of cells, columns and rows 1-based ("B6:H120" = columns 2-8, rows 6-120).
 */
final readonly class CellRange
{
    public const int MAX_ROW = 1_048_576;
    public const int MAX_COLUMN = 16_384;

    public function __construct(
        public int $firstColumn,
        public int $firstRow,
        public int $lastColumn,
        public int $lastRow,
    ) {
    }

    /**
     * "B6:H120", "$B$6:$H$120", "C3" (one cell). Anything else, or a range outside a sheet: null.
     */
    public static function fromA1(string $reference): null|self
    {
        if (preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,7})(?::\$?([A-Za-z]{1,3})\$?(\d{1,7}))?$/', $reference, $match) !== 1) {
            return null;
        }

        $firstColumn = self::columnNumber($match[1]);
        $firstRow = (int) $match[2];
        $lastColumn = isset($match[3]) ? self::columnNumber($match[3]) : $firstColumn;
        $lastRow = isset($match[4]) ? (int) $match[4] : $firstRow;

        if (
            $firstRow < 1 || $lastRow < $firstRow || $lastRow > self::MAX_ROW
            || $lastColumn < $firstColumn || $lastColumn > self::MAX_COLUMN
        ) {
            return null;
        }

        return new self($firstColumn, $firstRow, $lastColumn, $lastRow);
    }

    /**
     * "A" = 1, "Z" = 26, "AA" = 27, "XFD" = 16384.
     */
    public static function columnNumber(string $letters): int
    {
        $number = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $number = $number * 26 + ord($letter) - 64;
        }

        return $number;
    }

    public static function columnLetters(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $number = intdiv($number - 1 - $remainder, 26);
        }

        return $letters;
    }

    public function columns(): int
    {
        return $this->lastColumn - $this->firstColumn + 1;
    }

    public function rows(): int
    {
        return $this->lastRow - $this->firstRow + 1;
    }

    public function contains(int $column, int $row): bool
    {
        return $column >= $this->firstColumn && $column <= $this->lastColumn
            && $row >= $this->firstRow && $row <= $this->lastRow;
    }

    public function toA1(): string
    {
        $first = self::columnLetters($this->firstColumn) . $this->firstRow;
        $last = self::columnLetters($this->lastColumn) . $this->lastRow;

        return $first === $last ? $first : $first . ':' . $last;
    }
}
