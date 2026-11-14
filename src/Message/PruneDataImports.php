<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * The imports' daily clean-up (docs/features/data-import/upload.md "Prune"): uploads never confirmed, files kept
 * long enough, discarded imports.
 *
 * Handled outside a transaction: the handler reads what is due and deletes the objects (up to hundreds of calls to
 * object storage) - what that means for the rows is written by RecordDataImportsPruned, a batch at a time.
 */
readonly final class PruneDataImports implements HandledOutsideTransaction
{
}
