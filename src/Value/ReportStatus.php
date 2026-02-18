<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
