<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;

/**
 * A table on a sheet: where its header is and which rows and columns hold its data (docs/features/data-import/columns.md
 * "Where the data is"). A sheet may hold several - a table under a dashboard, twenty small ones side by side; one of
 * them is the sheet's data, the others are listed.
 */
final readonly class DataRegion
{
    public function __construct(
        public int $firstColumn,
        public int $lastColumn,
        /** null: the table has no header row - its columns are "Column N" */
        public null|int $headerRow,
        public int $firstRow,
        /** null: to the sheet's last row */
        public null|int $lastRow,
        public DataRegionSource $source,
        /** An Excel table's name */
        public null|string $name = null,
        /** About how many rows of data it holds - for choosing the largest, never for reading */
        public int $rows = 0,
        /** The table is wider than a mapped region may be (DataImportLimits::MAX_REGION_COLUMNS): cut on its right */
        public bool $cut = false,
    ) {
    }

    public function columns(): int
    {
        return $this->lastColumn - $this->firstColumn + 1;
    }

    public function holdsRow(int $rowNumber): bool
    {
        return $rowNumber >= $this->firstRow && ($this->lastRow === null || $rowNumber <= $this->lastRow);
    }

    public function holdsColumn(int $column): bool
    {
        return $column >= $this->firstColumn && $column <= $this->lastColumn;
    }

    /**
     * "A6:K200" - or "A6:K" for a table that runs to the sheet's end.
     */
    public function range(): string
    {
        return CellRange::columnLetters($this->firstColumn) . ($this->headerRow ?? $this->firstRow)
            . ':' . CellRange::columnLetters($this->lastColumn) . ($this->lastRow ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'range' => $this->range(),
            'firstColumn' => $this->firstColumn,
            'lastColumn' => $this->lastColumn,
            'headerRow' => $this->headerRow,
            'firstRow' => $this->firstRow,
            'lastRow' => $this->lastRow,
            'source' => $this->source->value,
            'name' => $this->name,
            'rows' => $this->rows,
            'cut' => $this->cut,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $region = new self(
            StoredShape::int($data, 'firstColumn', 1, DataImportLimits::MAX_COLUMNS),
            StoredShape::int($data, 'lastColumn', 1, DataImportLimits::MAX_COLUMNS),
            StoredShape::nullableInt($data, 'headerRow', 1),
            StoredShape::int($data, 'firstRow', 1),
            StoredShape::nullableInt($data, 'lastRow', 1),
            StoredShape::enum($data, 'source', DataRegionSource::class),
            StoredShape::nullableString($data, 'name', 300),
            StoredShape::int($data, 'rows'),
            StoredShape::bool($data, 'cut'),
        );

        if (
            $region->lastColumn < $region->firstColumn
            || $region->columns() > DataImportLimits::MAX_REGION_COLUMNS
            || ($region->lastRow !== null && $region->lastRow < $region->firstRow)
            || ($region->headerRow !== null && $region->headerRow >= $region->firstRow)
        ) {
            throw new InvalidImportMapping('A table\'s rows and columns do not fit together.');
        }

        return $region;
    }
}
