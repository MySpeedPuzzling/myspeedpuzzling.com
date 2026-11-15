<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The file's sha256 is known. When the player already has a live import of the very same bytes, this one is closed
 * in favour of that one (docs/features/data-import/reading.md "The same file again"). Answers whether reading goes on.
 *
 * Works on fresh state (RequiresFreshEntityManagerState): the worker's entity manager may still hold the import as it
 * was when the read began - a discard or a new attempt by another process must be seen before anything is written.
 */
readonly final class RecordImportFileHash implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public string $sha256,
    ) {
    }
}
