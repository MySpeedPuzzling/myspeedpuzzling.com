<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What approving a puzzle does with its brand (docs/features/puzzle-approvals.md).
 */
enum PuzzleApprovalBrandChoice: string
{
    // Leave the brand as it is (the usual case - it is already approved)
    case Keep = 'keep';
    // The puzzle's brand is new and genuine: approve it too
    case Approve = 'approve';
    // Move only this puzzle to another, approved brand
    case UseExisting = 'use_existing';
    // The puzzle's new brand duplicates an approved one: move all its puzzles there and delete it
    case MergeInto = 'merge_into';
}
