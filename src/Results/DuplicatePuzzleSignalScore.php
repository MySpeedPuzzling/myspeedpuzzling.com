<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Services\DuplicateResults\DuplicatePuzzleSignalScoring;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalReason;

/**
 * How strongly a catalogue signal points at one puzzle entered twice, and why (DuplicatePuzzleSignalScoring).
 */
readonly final class DuplicatePuzzleSignalScore
{
    /**
     * @param list<DuplicatePuzzleSignalReason> $reasons
     */
    public function __construct(
        public int $score,
        public array $reasons,
        // Kept for the "similar name 0.82" badge, rounded to two decimals
        public float $nameSimilarity,
    ) {
    }

    public function isWeak(): bool
    {
        if ($this->score < DuplicatePuzzleSignalScoring::STRONG_MIN_SCORE) {
            return true;
        }

        foreach ($this->reasons as $reason) {
            if ($reason->isCounterEvidence()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function reasonValues(): array
    {
        return array_map(static fn (DuplicatePuzzleSignalReason $reason): string => $reason->value, $this->reasons);
    }
}
