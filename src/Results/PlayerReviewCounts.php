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
        // Results awaiting verification the player was told about and has not answered yet
        // (docs/features/suspicious-time-review.md, "Where they see it")
        public int $suspiciousTimes = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * Everything waiting on "Review your results" - the number of the Menu's row (docs/features/navigation README
     * §4.12, MENU-01: menu_counts), what the banner counts.
     */
    public function total(): int
    {
        return $this->duplicates + $this->autoRemoved + $this->firstTryConflicts + $this->suspiciousTimes;
    }
}
