<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The worker takes an uploaded import for reading: `uploaded` → `reading`, in a transaction of its own **before**
 * the file is touched - so a read that is killed leaves an import that says "being read" (rescued by
 * ReadWaitingImports), never one that is read again and again. Answers Value\ImportReadingJob, or null when there
 * is nothing to read (read already, being read, discarded, the file gone).
 */
readonly final class BeginImportReading
{
    public function __construct(
        public string $importId,
    ) {
    }
}
