<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum PuzzleReportStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    // Closed without a review: the catalogue already did what it asked (PuzzleReportOutdatedReason) - shown as
    // "Already done", never told to the reporter (docs/features/puzzle-approvals.md, "Outdated requests")
    case Outdated = 'outdated';
}
