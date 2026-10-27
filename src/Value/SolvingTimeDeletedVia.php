<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a solving time was deleted - stored on its grave (docs/features/api/solving-time-sync.md "Graveyard").
 */
enum SolvingTimeDeletedVia: string
{
    case Web = 'web';
    case Api = 'api';
    // The player kept one copy of a result saved several times (review page, e-mail)
    case DuplicateKeptCopy = 'duplicate_kept_copy';
    // A certain duplicate removed automatically (Undo brings it back with the same id)
    case DuplicateAutoRemoval = 'duplicate_auto_removal';
}
