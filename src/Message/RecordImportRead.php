<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Every sheet of the file was read: `reading` → `setting_up`.
 *
 * Works on fresh state (RequiresFreshEntityManagerState): the worker's entity manager may still hold the import as it
 * was when the read began - a discard or a new attempt by another process must be seen before anything is written.
 */
readonly final class RecordImportRead implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public int $readAttempt,
    ) {
    }
}
