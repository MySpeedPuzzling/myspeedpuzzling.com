<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Recognises what every sheet of a read import holds and proposes its mapping (docs/features/data-import/columns.md).
 * On the `imports` queue, sent in the transaction that records the file as read (RecordImportReadHandler).
 *
 * Handled outside a transaction: it reads samples and up to a few thousand rows per sheet; what it finds is written
 * by RecordImportMappings in one short transaction. Sent twice, the second finds every sheet proposed.
 */
readonly final class ProposeImportMappings implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
    ) {
    }
}
