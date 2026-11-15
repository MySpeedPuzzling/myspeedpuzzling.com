<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Reading ended without the rows: what was written so far is removed, the import is `read_failed` with its reason
 * (a SpreadsheetUnreadableReason's value, or DataImportReadFailure's). The file stays - "Try again", or discard.
 *
 * Either by the read itself ($readAttempt), or by the rescue of a read whose worker died ($withoutProgressSince: only
 * when the import is still being read and nothing was written since then - checked again inside the transaction).
 *
 * Works on fresh state (RequiresFreshEntityManagerState): the worker's entity manager may still hold the import as it
 * was when the read began - a discard or a new attempt by another process must be seen before anything is written.
 */
readonly final class RecordImportReadFailed implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public string $reason,
        public null|int $readAttempt = null,
        public null|DateTimeImmutable $withoutProgressSince = null,
    ) {
    }
}
