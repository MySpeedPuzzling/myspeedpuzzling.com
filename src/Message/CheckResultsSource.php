<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * "Check sheet" on the results import page (docs/features/event-results-import/results-import-page.md): read the
 * source's sheet and keep it as a check. Outside a transaction - Google answers in seconds; the check is written by
 * RecordResultsImportCheck, in a transaction of its own. Nothing of the events is applied.
 */
readonly final class CheckResultsSource implements HandledOutsideTransaction
{
    public function __construct(
        public string $checkId,
        public string $sourceId,
        public string $checkedByPlayerId,
    ) {
    }
}
