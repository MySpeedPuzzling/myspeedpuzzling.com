<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Queues again what a read import still waits for by itself (docs/features/data-import/columns.md "The safety
 * net"): the proposal of its sheets' mappings, the parsing of a mapped sheet - for an import whose message was lost,
 * or that was read before this step existed. Stamps the import, so the next runs leave it alone for a while.
 * Answers whether anything was queued.
 */
readonly final class RequestImportSetUp implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
    ) {
    }
}
