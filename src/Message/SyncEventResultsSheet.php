<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Read an organizer's results sheet from Google and apply every tab (docs/features/event-results-import/README.md).
 * Outside a transaction: Google answers in seconds - each tab is applied by a message of its own (ApplyEventResultsTab),
 * the outcome of the read is recorded by RecordEventResultsSheetFetch.
 */
readonly final class SyncEventResultsSheet implements HandledOutsideTransaction
{
    public function __construct(
        public string $sheetId,
    ) {
    }
}
