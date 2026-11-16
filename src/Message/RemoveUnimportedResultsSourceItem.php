<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Before an event imported from the organizer's previous spreadsheet moves to its key in the new one
 * (ApplyEventResultsTab::$replacesKey): what is stored under that key but was never imported (it waited for its puzzle
 * or had a problem) is removed - in a transaction of its own, the unique key allows one item per key. Nothing of an
 * imported item is ever removed here.
 */
readonly final class RemoveUnimportedResultsSourceItem
{
    public function __construct(
        public string $sourceId,
        public string $key,
    ) {
    }
}
