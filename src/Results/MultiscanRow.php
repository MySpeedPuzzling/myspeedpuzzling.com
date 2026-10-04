<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One tray row of the multiscan page, hydrated for rendering
 * (docs/features/multiscan/README.md §7).
 */
readonly final class MultiscanRow
{
    /**
     * @param string $key Identifies the row - one code can have several rows (a code several puzzles share)
     * @param 'resolved'|'ambiguous'|'unknown' $state
     * @param list<PuzzleOverview> $candidates Ambiguous rows only: the puzzles of the code no other row holds
     * @param array<string, string> $chipParams
     * @param array<string, string> $reasonParams
     */
    public function __construct(
        public string $key,
        public string $ean,
        public string $state,
        public null|PuzzleOverview $puzzle,
        public array $candidates,
        public null|string $chip,
        public array $chipParams,
        public bool $eligible,
        public null|string $reason,
        public array $reasonParams,
    ) {
    }

    public function isResolved(): bool
    {
        return $this->state === 'resolved' && $this->puzzle !== null;
    }
}
