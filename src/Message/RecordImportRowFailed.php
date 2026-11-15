<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A row of a batch threw: it is marked failed, so the batch goes on without it (never tried again by itself).
 */
readonly final class RecordImportRowFailed implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $rowId,
        public string $batchId,
    ) {
    }
}
