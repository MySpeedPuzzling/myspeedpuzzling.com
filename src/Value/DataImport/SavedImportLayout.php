<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;

/**
 * A layout a player saved, as the import reads it when it looks for one that fits a sheet (ImportLayoutMatcher).
 */
final readonly class SavedImportLayout
{
    /**
     * @param list<string> $headers the header keys of the sheet it was saved from, left to right
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $signature,
        public array $headers,
        public ImportLayoutContent $content,
        /** The player changed what a known export's columns are: only then it is applied to that export again */
        public bool $changesKnownExport,
        public DateTimeImmutable $lastUsedAt,
    ) {
    }
}
