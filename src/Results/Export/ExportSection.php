<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

/**
 * One table of a sectioned export: an XLSX sheet, a JSON key, a CSV file in the ZIP and an XML element,
 * all named `$name`. Every row holds a value for every column (docs/features/data-export.md).
 */
readonly final class ExportSection
{
    /**
     * @param list<string> $columns
     * @param list<array<string, scalar|null>> $rows
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $columns,
        public array $rows,
    ) {
    }
}
