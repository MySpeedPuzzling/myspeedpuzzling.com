<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A compared subject is either a player (their solo times) or an exact pair/team (`puzzling_team`).
 * The backing value is the prefix used in URLs (`p-<uuid>`, `t-<uuid>`).
 */
enum ComparisonSubjectType: string
{
    case Player = 'p';
    case Team = 't';
}
