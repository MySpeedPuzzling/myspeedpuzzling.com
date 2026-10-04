<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleHistoryEntryKind;

/**
 * One line of a puzzle's history: who decided what about it, when, and what it changed.
 */
readonly final class PuzzleHistoryEntry
{
    /**
     * @param list<PuzzleHistoryChange> $changes What the decision changed on the puzzle, before and after
     * @param list<PuzzleHistoryChange> $proposal What a change request proposed (as it was, as proposed) - shown when
     *                                           it was rejected, or when the approval did not record what it changed
     * @param null|list<string> $appliedFields Older approvals recorded only which proposed fields were applied
     * @param list<PuzzleHistoryPuzzle> $puzzles The other puzzles of a merge
     * @param array<string, int> $movedRecords What a merge moved onto this puzzle - label => count
     */
    public function __construct(
        public PuzzleHistoryEntryKind $kind,
        public DateTimeImmutable $at,
        public null|string $byId,
        public null|string $byName,
        public null|string $byCode,
        public bool $viaInternalApi,
        public null|string $note,
        public array $changes = [],
        public array $proposal = [],
        public null|array $appliedFields = null,
        public null|string $changeRequestId = null,
        public null|string $mergeRequestId = null,
        public null|string $proposedById = null,
        public null|string $proposedByName = null,
        public null|string $proposedByCode = null,
        public array $puzzles = [],
        public array $movedRecords = [],
        // Brand lines: the brand approved, or the brand merged away and the one it went into
        public null|string $brandName = null,
        public null|string $intoBrandName = null,
        public null|string $brandChoice = null,
    ) {
    }
}
