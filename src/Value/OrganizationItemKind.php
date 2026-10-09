<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What AssignEventToOrganization moves into (or out of) an organization: a series or a one-time event. An edition
 * never has an organization of its own - it is its series'.
 */
enum OrganizationItemKind: string
{
    case Series = 'series';
    case Competition = 'competition';
}
