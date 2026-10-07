<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum MergeDecisionSource: string
{
    case AdminUi = 'admin_ui';
    case InternalApi = 'internal_api';
    // Nobody decided: the application closed an outdated request (OutdatedPuzzleRequests)
    case Automatic = 'automatic';
}
