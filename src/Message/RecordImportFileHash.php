<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The file's sha256 is known. When the player already has a live import of the very same bytes, this one is closed
 * in favour of that one (docs/features/data-import/reading.md "The same file again"). Answers whether reading goes on.
 */
readonly final class RecordImportFileHash
{
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public string $sha256,
    ) {
    }
}
