<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Every decision a moderator or admin makes about the puzzle catalogue, as
 * recorded in puzzle_moderation_decision. Values are stored - never rename one.
 */
enum PuzzleModerationAction: string
{
    case ChangeRequestApproved = 'change_request_approved';
    case ChangeRequestRejected = 'change_request_rejected';
    case MergeRequestApproved = 'merge_request_approved';
    case MergeRequestRejected = 'merge_request_rejected';
    case PuzzleApproved = 'puzzle_approved';
    case BrandApproved = 'brand_approved';
    case BrandMerged = 'brand_merged';
}
