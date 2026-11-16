<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Import" on the results import page (docs/features/event-results-import/results-import-page.md "As built: PR 3"): the
 * ticked events of a stored check, parsed again through the source's format - never a new read of Google. Each event is
 * applied by ApplyEventResultsTab in a transaction of its own (so outside one here), the outcome is logged by
 * RecordResultsImport under $importId. One import of a source at a time; the same $importId twice imports nothing.
 */
readonly final class ImportResultsFromCheck implements HandledOutsideTransaction, SerializedByLock
{
    /**
     * @param list<string> $eventKeys
     */
    public function __construct(
        public string $importId,
        public string $sourceId,
        public string $checkId,
        public array $eventKeys,
        public string $importedByPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'results-import-' . strtolower($this->sourceId);
    }
}
