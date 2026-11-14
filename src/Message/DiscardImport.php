<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player throws an import away (docs/features/data-import/upload.md): its file leaves object storage, its sheets
 * and rows are deleted, the import is marked discarded and removed whole by the daily prune.
 */
readonly final class DiscardImport
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }
}
