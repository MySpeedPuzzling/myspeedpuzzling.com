<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A step that would raise the player's MSP rating (RatingOpportunities).
 */
enum RatingMoveKind: string
{
    /** An unsolved puzzle from the shelf: a new first-attempt and a new best-time entry */
    case FirstTry = 'first_try';
    /** Solve a rated puzzle again: a better best time, or at least a fresh one (its decay starts over) */
    case Resolve = 'resolve';
    /** A re-solve of a best-time entry that leaves the 24-month window within 60 days */
    case Defend = 'defend';
}
