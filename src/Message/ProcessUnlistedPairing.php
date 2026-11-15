<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * A turn of a pairing of an unlisted puzzle - or of its Undo - on the `imports` queue: at most
 * DataImportLimits::ROWS_PER_TURN results and marks, then the message sends itself again. Handled outside a
 * transaction: every result is a small message of its own, handled at once in its own transaction
 * (ApplyUnlistedPairingStep). Everything is driven by the state of the results - sent twice or delivered again, it
 * goes on where they say and converts nothing twice.
 */
readonly final class ProcessUnlistedPairing implements HandledOutsideTransaction
{
    public function __construct(
        public string $pairingId,
    ) {
    }
}
