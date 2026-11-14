<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

/**
 * One table of a sectioned export: an XLSX sheet, a JSON key, a CSV file in the ZIP and an XML element,
 * all named `$name`. Every row holds a value for every column (docs/features/data-export.md).
 *
 * A section may end with the downloading player's own fields (OwnCustomColumns; docs/features/data-export.md "Own
 * fields"): their labels after the columns, and per row - in the order of `$rows` - what each field holds there.
 */
readonly final class ExportSection
{
    /**
     * @param list<string> $columns
     * @param list<array<string, scalar|null>> $rows
     * @param list<string> $customColumns
     * @param list<array<string, null|string|int|float|bool|list<string>>> $customRows every label of $customColumns
     *                                                                                 per row, null = empty
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $columns,
        public array $rows,
        public array $customColumns = [],
        public array $customRows = [],
    ) {
    }

    /**
     * @return array<string, null|string|int|float|bool|list<string>> the row's own fields by label, in column order
     */
    public function customRow(int $index): array
    {
        $row = [];

        foreach ($this->customColumns as $label) {
            $row[$label] = $this->customRows[$index][$label] ?? null;
        }

        return $row;
    }
}
