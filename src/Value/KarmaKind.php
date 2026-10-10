<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One line of a player's karma (docs/features/karma.md): every kind but DuplicatePuzzle is worth exactly 1.
 */
enum KarmaKind: string
{
    /** A new puzzle with an EAN, approved - credited to whoever added the earliest copy of it */
    case PuzzleAdded = 'puzzle_added';
    /** An approved change request (scanned EANs included) */
    case ChangeRequest = 'change_request';
    /** An approved merge request */
    case MergeRequest = 'merge_request';
    /** A moderator's decision on a change request, a merge request or a new puzzle */
    case Decision = 'decision';
    /** A moderator's direct edit of a puzzle */
    case DirectEdit = 'direct_edit';
    /** Not counted: a puzzle the player added that turned out to be a copy of one added earlier */
    case DuplicatePuzzle = 'duplicate_puzzle';

    public function counts(): bool
    {
        return $this !== self::DuplicatePuzzle;
    }

    public function group(): null|KarmaGroup
    {
        return match ($this) {
            self::PuzzleAdded => KarmaGroup::Puzzles,
            self::ChangeRequest, self::MergeRequest => KarmaGroup::Fixes,
            self::Decision, self::DirectEdit => KarmaGroup::Moderation,
            self::DuplicatePuzzle => null,
        };
    }
}
