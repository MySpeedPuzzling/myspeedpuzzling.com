<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a merge or change request was closed as outdated (PuzzleReportStatus::Outdated). Values are stored - never
 * rename one.
 */
enum PuzzleReportOutdatedReason: string
{
    // A merge request whose puzzles are one puzzle now - other merges joined them
    case AlreadyMerged = 'already_merged';
    // A merge request left with fewer than two puzzles, at least one of them deleted without a merge
    case PuzzlesGone = 'puzzles_gone';
    // A change request whose every proposed value the puzzle has already
    case AlreadyApplied = 'already_applied';

    public function label(): string
    {
        return match ($this) {
            self::AlreadyMerged => 'Already merged',
            self::PuzzlesGone => 'Puzzles no longer exist',
            self::AlreadyApplied => 'Already applied',
        };
    }
}
