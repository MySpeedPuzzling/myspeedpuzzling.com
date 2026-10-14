<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * How the viewer stands to the new home of a "Where did it go?" row (WhereDidItGoBuilder::standing(), LAUNCH-02); a
 * row whose home is not for the viewer at all is left out (null there).
 */
enum WhereDidItGoStanding
{
    // The viewer has it there
    case Open;
    // Members only, and the viewer is no member: the lock and "Members" (a guest also finds it after signing in)
    case MembersOnly;
    // A guest, and the new home is for signed-in players: "After signing in" where following it needs an account
    case Guest;
}
