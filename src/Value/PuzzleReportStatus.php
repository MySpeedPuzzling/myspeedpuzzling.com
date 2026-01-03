<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleReportStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
