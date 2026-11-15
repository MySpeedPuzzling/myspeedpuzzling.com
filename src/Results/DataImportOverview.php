<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * One of the signed-in player's imports as their own pages show it (GetDataImports).
 */
readonly final class DataImportOverview
{
    public function __construct(
        public string $importId,
        public string $fileName,
        public null|int $fileSize,
        public DataImportStatus $status,
        public DateTimeImmutable $createdAt,
        public bool $fileKept,
        public null|DateTimeImmutable $fileRemovedAt,
        /** The day the file leaves object storage - null when it is gone already */
        public null|DateTimeImmutable $fileKeptUntil,
        /** Why the file was not read, as the page has a sentence for it - null unless reading failed */
        public null|string $readFailure = null,
        /** Known once the file was opened */
        public null|int $sheetsTotal = null,
        public int $sheetsRead = 0,
        public int $rowsRead = 0,
    ) {
    }
}
