<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a pair/team's results say about solving together (PairsTeamsSynergy) - neutral words in the page, never blame.
 */
enum SynergyVerdict: string
{
    /** Together faster than your solo paces predict, the whole range beyond 1.5 % */
    case GreatMatch = 'great_match';
    case AsExpected = 'as_expected';
    /** Together slower than your solo paces predict, the whole range beyond 1.5 % */
    case FasterApart = 'faster_apart';
    /** Fewer than 5 results that can be compared (rated, boxed, every member with a pace at the solve) */
    case NotSureYet = 'not_sure_yet';
    /** A guest is in it: a guest has no solo pace - listed without a score */
    case NoSoloPace = 'no_solo_pace';
}
