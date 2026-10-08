<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum FollowTargetKind: string
{
    case Competition = 'competition';
    case Series = 'series';
    case Organization = 'organization';
}
