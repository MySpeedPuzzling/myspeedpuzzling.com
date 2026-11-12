<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventTab;

/**
 * One tab of an organizer's results sheet, read and checked - applied as an upsert in a transaction of its own
 * (docs/features/event-results-import/README.md). Two runs never apply the same tab at once.
 */
readonly final class ApplyEventResultsTab implements SerializedByLock
{
    public function __construct(
        public string $sheetId,
        public string $gid,
        public string $title,
        public ParsedEventTab $tab,
    ) {
    }

    public function lockKey(): string
    {
        return 'event-results-tab-' . strtolower($this->sheetId) . '-' . $this->gid;
    }
}
