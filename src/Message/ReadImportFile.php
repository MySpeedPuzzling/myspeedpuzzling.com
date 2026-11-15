<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Reads an uploaded import's file into its sheets and rows (docs/features/data-import/reading.md). On the `imports`
 * queue, sent when an import becomes `uploaded` (and by `myspeedpuzzling:read-waiting-imports` for one nobody
 * started to read).
 *
 * Handled outside a transaction: the handler downloads the file and reads it - seconds to minutes; everything it
 * finds is written by small messages of their own (BeginImportReading, RecordImportFileHash, RecordImportSheets,
 * RecordImportRows, RecordImportRead, RecordImportReadFailed), each in its own short transaction. Sent twice, the
 * second one finds nothing to read.
 */
readonly final class ReadImportFile implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
    ) {
    }
}
