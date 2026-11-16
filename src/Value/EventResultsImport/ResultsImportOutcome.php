<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What an import from the results import page made of one ticked event - kept in results_import_log.events, the end of
 * its translation key `results_import.outcome.<value>`.
 */
enum ResultsImportOutcome: string
{
    // Created or updated - its results are published
    case Imported = 'imported';
    // Imported exactly like this before - nothing done
    case Unchanged = 'unchanged';
    // Somebody imported a newer check of it meanwhile - left as they published it
    case Stale = 'stale';
    // Not importable after all (waiting for its puzzle, a problem found while applying, its event deleted)
    case NotImported = 'not_imported';
    // Not in the check any more (the page was older than the check)
    case NotInCheck = 'not_in_check';
    // Something went wrong while applying it - nothing of it changed
    case Failed = 'failed';
}
