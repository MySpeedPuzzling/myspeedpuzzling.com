<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum ImportedResultStatus: string
{
    // A new result exists
    case Added = 'added';
    // Nothing was written: the row, or another row with the same key, already made a result that still exists
    case AlreadyAdded = 'already_added';
    // Nothing was written: the result is not a correct one - the row goes back to "To fix" with the reason
    case Refused = 'refused';
}
