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
     * @param 'resolved'|'ambiguous'|'unknown' $state
     * @param list<PuzzleOverview> $candidates Ambiguous rows only
     * @param array<string, string> $chipParams
     * @param array<string, string> $reasonParams
     */
    public function __construct(
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
