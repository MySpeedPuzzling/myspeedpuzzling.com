<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * docs/features/event-results-import/README.md "Tab outcomes"
 */
enum EventTabStatus: string
{
    case Imported = 'imported';
    case WaitingForPuzzle = 'waiting_for_puzzle';
    case Problem = 'problem';
    // The event made from this tab was deleted on MySpeedPuzzling - it is never re-created
    case Detached = 'detached';
}
