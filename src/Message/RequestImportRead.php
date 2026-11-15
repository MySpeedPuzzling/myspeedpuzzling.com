<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The safety net asks for the read of an import that waits in `uploaded` (ReadWaitingImports): stamped
 * (`read_requested_at`) and queued (ReadImportFile) in one transaction, so the same import is not queued again by
 * every run. Answers whether it was queued - not when it is being read meanwhile, or was asked for a moment ago.
 */
readonly final class RequestImportRead implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
    ) {
    }
}
