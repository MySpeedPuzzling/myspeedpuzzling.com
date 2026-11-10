<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Release 1 of 2 (docs/TODO.md "PhotoStash descriptions in Postgres"): every still valid old-format description
 * (`tmp-uploads/<player>/<token>.json`, written by the previous release) gets its photo_stash_entry row, so release 2
 * can stop reading the old format. Idempotent; `write` false = only count.
 *
 * Handled outside a transaction: the handler lists and reads object storage (slow) and writes each row through
 * RecordPhotoStashEntry - its own transaction.
 */
readonly final class BackfillPhotoStashEntries implements HandledOutsideTransaction
{
    public function __construct(
        public bool $write = false,
    ) {
    }
}
