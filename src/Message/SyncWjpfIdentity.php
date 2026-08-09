<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Check one player against the WJPF database and record the outcome.
 *
 * Deliberately one player per message: the doctrine_transaction middleware wraps every
 * handler in a transaction, so a batch-shaped message would hold a single transaction open
 * for the whole (multi-hour) backfill.
 */
readonly final class SyncWjpfIdentity
{
    public function __construct(
        public string $playerId,
        /**
         * Send our id so their side stores it. False performs a read-only survey - their
         * conditional UPDATE then writes an empty string over an already-empty column.
         */
        public bool $claim = false,
    ) {
    }
}
