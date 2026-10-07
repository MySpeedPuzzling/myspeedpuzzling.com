<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one change of a participants sheet change set does (SheetChange) - the `op` of the wire format,
 * docs/features/competitions-management/participants-spreadsheet.md §6.
 */
enum SheetChangeOp: string
{
    case NewParticipant = 'newParticipant';
    case Field = 'field';
    case Player = 'player';
    case Place = 'place';
    case NewTeam = 'newTeam';
    case RenameTeam = 'renameTeam';
    case DeleteTeam = 'deleteTeam';
    case Remove = 'remove';
    case Restore = 'restore';
    case TeamSize = 'teamSize';
}
