<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Try again" on an import whose Google sheet was not fetched: `fetch_failed` → `fetching`, queued for the worker
 * again. Answers whether it waits again (false: it is not one whose fetch failed).
 */
readonly final class FetchImportAgain
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }
}
