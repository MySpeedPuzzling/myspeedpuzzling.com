<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Outcome of the most recent check of one player against the WJPF player database.
 */
enum WjpfPairingStatus: string
{
    /**
     * Their record is linked to this player - either their MySpeedPuzzlingId column was
     * empty and we claimed it, or it already held our id.
     */
    case Paired = 'paired';

    /** The e-mail was checked against their database and matched no row. */
    case NotFound = 'not_found';

    /**
     * Their record already points at a *different* MySpeedPuzzling id. We keep our half of
     * the mapping anyway, but theirs disagrees - and their write guard
     * (`if (!$fila['MySpeedPuzzlingId'])`) means no call of ours can ever correct it.
     * Every transition into this state is logged at warning level.
     */
    case Conflict = 'conflict';
}
