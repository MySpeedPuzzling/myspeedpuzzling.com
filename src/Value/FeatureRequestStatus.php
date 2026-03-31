<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum FeatureRequestStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';
}
