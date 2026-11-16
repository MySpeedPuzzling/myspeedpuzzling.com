<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;

/**
 * One event of an organizer's results source, read and checked by the source's format - applied as an upsert in a
 * transaction of its own (docs/features/event-results-import/README.md). Stored as the source's tab keyed by the
 * event's stable key (event_results_sheet_tab.gid). Two runs never apply the same event at once.
 */
readonly final class ApplyEventResultsTab implements SerializedByLock
{
    public function __construct(
        public string $sheetId,
        public ParsedEvent $event,
    ) {
    }

    public function lockKey(): string
    {
        return 'event-results-tab-' . strtolower($this->sheetId) . '-' . $this->event->key;
    }
}
