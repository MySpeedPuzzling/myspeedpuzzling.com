<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the player stands with the MSP rating at a piece count (RatingOpportunities).
 */
enum RatingStanding: string
{
    case OnLadder = 'on_ladder';
    /** Not on the ladder yet, at most RatingOpportunities::LADDER_REACH new results away - the path is shown */
    case NearLadder = 'near_ladder';
    /** Not on the ladder and further away - the moves are what the entries are worth, no path */
    case OffLadder = 'off_ladder';
    /** No rating entries at all */
    case NoEntries = 'no_entries';
    /** Private players have no MSP rating (rankings are public-only) */
    case Private = 'private';
}
