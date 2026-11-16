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
    // Merge requests only: an approved merge an admin undid (RevertPuzzleMergeRequestHandler) - the merged puzzles
    // exist again, the request counts as neither approved nor rejected
    case Reverted = 'reverted';
}
