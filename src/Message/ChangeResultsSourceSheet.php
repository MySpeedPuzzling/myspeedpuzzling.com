<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Change sheet" on the results import page (docs/features/event-results-import/results-import-page.md "As built:
 * PR 4"): the organization's team points its results source at another spreadsheet. The new one is read once - outside
 * a transaction, Google answers in seconds - to prove it is shared with the link and readable; only then is it saved,
 * with that read as the source's check, by RecordResultsSourceSheetChange in a transaction of its own. Under the
 * source's import lock: never in the middle of an import. The handler answers ResultsSourceSheetChange.
 */
readonly final class ChangeResultsSourceSheet implements HandledOutsideTransaction, SerializedByLock
{
    public function __construct(
        public string $checkId,
        public string $sourceId,
        public string $spreadsheetId,
        public string $changedByPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'results-import-' . strtolower($this->sourceId);
    }
}
