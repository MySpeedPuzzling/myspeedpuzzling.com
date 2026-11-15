<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * The daily sweep's question about one import (PruneDataImportsHandler): is nothing of it left to add? Then it is
 * finished - the handler looks again, in its own transaction. Answers whether it finished the import.
 */
readonly final class FinishSettledImport implements SerializedByLock
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-add-' . strtolower($this->playerId);
    }
}
