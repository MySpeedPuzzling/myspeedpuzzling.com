<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What became of "Change sheet" on the results import page (ChangeResultsSourceSheet) - the end of its flash's
 * translation key `results_import.sheet.flash.<value>`. Only Changed changes anything.
 */
enum ResultsSourceSheetChange: string
{
    // Read once (shared with the link, readable) and saved - its events are the latest check
    case Changed = 'changed';
    // The source reads this spreadsheet already
    case Same = 'same';
    // Another results source reads it (a spreadsheet belongs to one source)
    case Taken = 'taken';
    // Google did not give us its tabs - not shared "Anyone with the link: Viewer", or a wrong link
    case Unreadable = 'unreadable';
}
