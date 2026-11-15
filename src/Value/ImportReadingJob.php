<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the worker needs to read an import's file - plain values, no entity leaves the transaction that started the
 * read (BeginImportReading).
 */
readonly final class ImportReadingJob
{
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public string $fileKey,
        public DataImportFileKind $fileKind,
        public int $fileSize,
    ) {
    }
}
