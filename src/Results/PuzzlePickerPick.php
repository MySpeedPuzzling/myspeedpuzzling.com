<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One seeded draw of the puzzle picker: the sampled cards plus how many
 * puzzles matched the criteria in total ("1 of 87 matching").
 */
readonly final class PuzzlePickerPick
{
    /**
     * @param list<PuzzlePickerSuggestion> $suggestions
     */
    public function __construct(
        public array $suggestions,
        public int $totalMatching,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->suggestions === [];
    }
}
