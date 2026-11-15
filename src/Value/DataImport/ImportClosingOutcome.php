<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What "I'm done with this import" and "Go on with this import" answered (FinishImportHandler,
 * GoOnWithImportHandler). Never an exception: every one of these is something the page says.
 */
enum ImportClosingOutcome: string
{
    case Finished = 'finished';
    /** An "Add", an Undo or an answer for many rows of it is still running - it is finished when that is through */
    case Busy = 'busy';
    /** Not at a stage that can be finished: still being read, or its reading failed - such an import is discarded */
    case NotFinishable = 'not_finishable';
    case GoesOn = 'goes_on';
    /** The player's places are taken: another import is finished or discarded first */
    case NoFreePlace = 'no_free_place';
    /** Not finished, or its rows no longer hold what the spreadsheet said */
    case CanNotGoOn = 'can_not_go_on';
}
