<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Try again" on the page of an import whose file could not be read: it waits for the worker once more
 * (`read_failed` → `uploaded`, ReadImportFile queued). The player's own import only.
 */
readonly final class ReadImportAgain
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }
}
