<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What the reader fetched of an organizer's sheet at one moment - every tab with its CSV as Google exports it, in the
 * sheet's order. A format reads only this (docs/features/event-results-import/results-import-page.md "Three layers").
 */
readonly final class SheetSnapshot
{
    /**
     * @param list<SheetSnapshotTab> $tabs the tabs Google gave us
     * @param list<UnreadableSheetTab> $unreadableTabs listed on the sheet, but their CSV did not come - left out of
     *     $tabs, so no format ever reads one as empty
     */
    public function __construct(
        public array $tabs,
        public array $unreadableTabs = [],
    ) {
    }
}
