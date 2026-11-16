<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What importing one event of a check would do, compared with what is stored
 * (docs/features/event-results-import/results-import-page.md "Pages"). Decided in the order the import decides
 * (ApplyEventResultsTabHandler) - ResultsImportComparison. The value is the end of its translation key
 * `results_import.status.<value>`.
 */
enum ResultsImportStatus: string
{
    // Never imported (or only waited / had problems before) - importing creates the event
    case New = 'new';
    // Imported, and the sheet says something else now - importing updates the event and its results
    case Changed = 'changed';
    // Imported exactly like this - nothing to do
    case Unchanged = 'unchanged';
    // No link to the puzzle, or the linked puzzle is not on MySpeedPuzzling
    case WaitingForPuzzle = 'waiting_for_puzzle';
    // Something the organizer has to fix in the sheet - nothing of the event is imported
    case NeedsFixing = 'needs_fixing';
    // The event made from it was deleted on MySpeedPuzzling - it is never created again
    case EventDeleted = 'event_deleted';

    public function canBeImported(): bool
    {
        return $this === self::New || $this === self::Changed;
    }
}
