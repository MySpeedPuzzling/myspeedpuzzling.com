<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

/**
 * A sectioned export before it is rendered into a format: what it is (`about`, a flat key => value map) and its tables.
 */
readonly final class ExportDocument
{
    /**
     * @param array<string, scalar|null> $about
     * @param list<ExportSection> $sections
     */
    public function __construct(
        public string $rootName,
        public array $about,
        public array $sections,
    ) {
    }
}
