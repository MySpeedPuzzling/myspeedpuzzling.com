<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "I'm done with this import" (docs/features/data-import/upload.md "Finished"): nothing more is added from the
 * player's own import and it no longer holds one of their places. What it added stays and can be taken back.
 * Answers an ImportClosingOutcome.
 *
 * Serialized with the player's "Add" (the same key as AddImportRows): an import is never finished while an "Add" of
 * it is being queued.
 */
readonly final class FinishImport implements SerializedByLock
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
