<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Add": the player asks for rows of their import to be added to their profile
 * (docs/features/data-import/adding.md) - everything that is ready, or the rows / the distinct puzzles named. The
 * handler checks, queues the rows for a batch and hands the batch to the `imports` worker (ProcessImportBatch);
 * it answers with an AddImportRowsOutcome and adds nothing itself.
 *
 * `$requestId` is chosen by the page for one press of the button: sent again (a double tap, a retry, a second tab
 * with the same form) it answers the same batch.
 *
 * One "Add" of a player at a time: the check "is one running?", the daily count and the queueing must not interleave
 * with another request of the same player.
 */
readonly final class AddImportRows implements SerializedByLock
{
    /**
     * @param null|list<string> $rowIds only these rows; null with $importPuzzleIds null = every row that is ready
     * @param null|list<string> $importPuzzleIds only the rows of these distinct puzzles of the import
     */
    public function __construct(
        public string $requestId,
        public string $importId,
        public string $playerId,
        public null|array $rowIds = null,
        public null|array $importPuzzleIds = null,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-add-' . strtolower($this->playerId);
    }
}
