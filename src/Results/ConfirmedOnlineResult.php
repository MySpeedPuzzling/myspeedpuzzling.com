<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;

/**
 * What ConfirmOnlineResultHandler did (read it from the envelope's HandledStamp): the official result's outcome
 * (applied, or unchanged - a refusal throws OnlineResultRefused instead), the result the entry holds now, the confirmed
 * moment and its source on the online entry, and the round entries written - the controller tells the other open pages
 * about them after the commit (OfficialResultsLiveUpdates::entriesChanged()).
 */
readonly final class ConfirmedOnlineResult
{
    public function __construct(
        public RoundResultChangeStatus $status,
        public string $entryRef,
        public RoundEntryResult $result,
        // null: unfinished - the pieces are the result
        public null|int $momentMs,
        public OnlineConfirmedSource $source,
        /** @var list<string> RoundEntryRef strings of the round entries written - empty when nothing official changed */
        public array $changedEntryRefs,
        // The online entry took the decision now - false when it held exactly this decision already
        public bool $reviewRecorded,
    ) {
    }
}
