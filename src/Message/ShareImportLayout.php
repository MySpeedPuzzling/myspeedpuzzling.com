<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * The sheets of an import that a layout its player just confirmed fits follow it
 * (docs/features/data-import/layouts.md "One workbook"). On the `imports` queue, sent in the transaction that
 * confirmed the sheet (ImportLayouts).
 *
 * Handled outside a transaction: every sheet is worked out from a sample and up to a few thousand of its rows; what
 * is found is written by RecordSharedImportMappings in one short transaction. Sent twice, the second finds nothing
 * to change.
 */
readonly final class ShareImportLayout implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
        public string $layoutId,
    ) {
    }
}
