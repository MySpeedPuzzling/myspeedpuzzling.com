<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A Google sheet was not fetched: the import is `fetch_failed` with its reason (a GoogleSheetFetchFailure's value,
 * or DataImportReadFailure's). "Try again" fetches again.
 *
 * Either by the fetch itself ($fetchAttempt), or by the rescue of a fetch that never ended ($withoutProgressSince:
 * only when the import is still `fetching` and nothing happened since then - checked again inside the transaction).
 */
readonly final class RecordImportFetchFailed implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public string $reason,
        public null|int $fetchAttempt = null,
        public null|DateTimeImmutable $withoutProgressSince = null,
    ) {
    }
}
