<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\DataImportUndoScope;

/**
 * Undo (docs/features/data-import/adding.md "Undo"): chosen rows, one "Add", or everything an import added is taken
 * back - asked by the player, or by an admin for anybody ($asAdmin, with a reason). The handler marks the rows and
 * hands them to the `imports` worker (ProcessImportUndo); it answers with an AddImportRowsOutcome.
 *
 * A result somebody changed since the import (time, day, puzzle, people) is kept and counted - unless the player
 * asks for chosen rows with $includeEdited.
 */
readonly final class UndoImportRows implements SerializedByLock
{
    /**
     * @param null|list<string> $rowIds scope `rows`
     */
    public function __construct(
        public string $requestId,
        public string $importId,
        public string $requestedByPlayerId,
        public DataImportUndoScope $scope,
        public null|string $batchId = null,
        public null|array $rowIds = null,
        public bool $includeEdited = false,
        public bool $asAdmin = false,
        public null|string $reason = null,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-undo-' . strtolower($this->importId);
    }
}
