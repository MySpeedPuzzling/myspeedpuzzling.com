<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Fetches an import's Google sheet as .xlsx and hands it to the reading (docs/features/data-import/google-sheets.md).
 * On the `imports` queue, sent when the import is created (CreateImportFromGoogleSheet) and by "Try again"
 * (FetchImportAgain).
 *
 * Handled outside a transaction: the handler waits for Google - up to a minute. What it finds is written by small
 * messages of their own (BeginImportFetch, RecordImportFetched, RecordImportFetchFailed), each in its own short
 * transaction. Sent twice, the second one finds nothing to fetch.
 */
readonly final class StartImportFromGoogleSheet implements HandledOutsideTransaction
{
    public function __construct(
        public string $importId,
    ) {
    }
}
