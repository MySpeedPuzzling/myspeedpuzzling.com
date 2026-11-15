<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Parses the rows of one sheet for one version of its mapping (docs/features/data-import/columns.md "Parsing"):
 * raw cells + mapping + options → what each row is, its typed values, its problems, its keys. On the `imports`
 * queue.
 *
 * Handled outside a transaction, in batches of DataImportLimits::ROWS_PER_BATCH written by RecordParsedImportRows -
 * each a short transaction that is refused once the sheet has a newer mapping: **a job of an older version never
 * overwrites a newer one's result**. Sent twice for the same version, both write the same.
 */
readonly final class ParseImportSheet implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
        public string $sheetId,
        public int $mappingVersion,
    ) {
    }
}
