<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A Google sheet's export is in object storage under the import's key: `fetching` → `uploaded`. Answers whether it
 * was recorded - not when the import is not being fetched by that very fetch any more (discarded meanwhile, given up
 * as stalled, started again): the caller then removes what it stored.
 */
readonly final class RecordImportFetched implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public int $fetchAttempt,
        public null|string $fileName,
        public int $fileSize,
    ) {
    }
}
