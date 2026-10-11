<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two e-mails riding on the activity-digest switch (docs/features/content-digest/README.md): the activity digest
 * (Mondays, only to players with activity in their period - nobody gets an empty one) and "We miss you" (Tuesdays, to a
 * player whose last logged result is at least 28 days old - once per inactive spell).
 */
enum ContentDigestKind: string
{
    case Activity = 'activity';
    case MissYou = 'miss_you';
}
