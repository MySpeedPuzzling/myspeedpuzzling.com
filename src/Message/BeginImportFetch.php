<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The worker takes an import's Google sheet for fetching, in a transaction of its own **before** Google is asked.
 * Answers Value\ImportFetchJob, or null when there is nothing to fetch (taken already, fetched, failed, discarded).
 */
readonly final class BeginImportFetch implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
    ) {
    }
}
