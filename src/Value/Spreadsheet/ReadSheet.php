<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * A sheet of an opened workbook, known without reading its rows (ReadWorkbook::sheets()).
 */
final readonly class ReadSheet
{
    /**
     * @param list<ReadTable> $tables
     */
    public function __construct(
        /** 1 = the first tab */
        public int $position,
        /** '' for a CSV file */
        public string $title,
        public bool $hidden = false,
        public array $tables = [],
        public null|FrozenPane $frozenPane = null,
        /** What the file says about itself (may be wider than the values, or missing) - a hint, never a promise */
        public null|CellRange $usedRange = null,
    ) {
    }
}
