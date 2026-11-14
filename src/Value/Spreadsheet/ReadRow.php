<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * One row with at least one value.
 */
final readonly class ReadRow
{
    /**
     * @param non-empty-array<int, ReadCell> $cells column (1 = A) => cell, in column order; empty cells are not there
     */
    public function __construct(
        /** The row number a spreadsheet shows (1-based; rows without a value are skipped, so numbers have gaps) */
        public int $number,
        public array $cells,
    ) {
    }

    public function cell(int $column): null|ReadCell
    {
        return $this->cells[$column] ?? null;
    }

    public function lastColumn(): int
    {
        return array_key_last($this->cells);
    }
}
