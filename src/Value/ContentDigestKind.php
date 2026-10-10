<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two e-mails of the digest: the activity digest (Monday, players with activity in the period) and the quiet
 * "no activity" e-mail (the day after, at most once until the player is active again).
 */
enum ContentDigestKind: string
{
    case Activity = 'activity';
    case NoActivity = 'no_activity';
}
