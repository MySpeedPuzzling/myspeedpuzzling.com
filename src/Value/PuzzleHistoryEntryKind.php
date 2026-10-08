<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One line of a puzzle's history (GetPuzzleHistory) - read from the decision log, never stored.
 */
enum PuzzleHistoryEntryKind: string
{
    case Edited = 'edited';
    case ChangeRequestApproved = 'change_request_approved';
    case ChangeRequestRejected = 'change_request_rejected';
    // An EAN a player scanned for a puzzle without one - written at once, the approved change request is its record
    case EanLinked = 'ean_linked';
    // Duplicates were merged into this puzzle
    case MergeApproved = 'merge_approved';
    // This puzzle was merged into another one and deleted
    case MergedAway = 'merged_away';
    case MergeRejected = 'merge_rejected';
    // Closed by the application, nothing left to do (OutdatedPuzzleRequests) - nobody decided
    case ChangeRequestOutdated = 'change_request_outdated';
    case MergeOutdated = 'merge_outdated';
    case Approved = 'approved';
    case BrandApproved = 'brand_approved';
    case BrandMerged = 'brand_merged';
}
