<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the "Review your results" banner counts for one player (docs/features/duplicate-results.md, "Banner").
 */
readonly final class PlayerReviewCounts
{
    public function __construct(
        // Open duplicate cases of the player whose two copies both still exist
        public int $duplicates,
        // Copies removed automatically in the last 30 days, not undone
        public int $autoRemoved,
        // Puzzles with more than one first try (docs/features/first-try-integrity.md)
        public int $firstTryConflicts,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->duplicates === 0 && $this->autoRemoved === 0 && $this->firstTryConflicts === 0;
    }
}
