<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Everything the add/edit forms check about a result before it is saved, decided from one read of the puzzle's
 * results: the first-try rules (only when the tag is ticked) and the "same time already saved" check (only with a
 * time). Null = not checked.
 */
readonly final class ResultEntryCheck
{
    public function __construct(
        public null|FirstTryAssessment $firstTry,
        public null|DuplicateAssessment $duplicates,
    ) {
    }

    public static function nothing(): self
    {
        return new self(null, null);
    }

    public function duplicateBlocks(bool $duplicateConfirmed): bool
    {
        return $this->duplicates?->blocks($duplicateConfirmed) === true;
    }

    /**
     * A copy of a result must not be offered "Make this result my first try" - the first-try rules speak only once
     * the same-day twin is answered.
     */
    public function firstTryBlocks(FirstTryResolution $resolution, bool $duplicateConfirmed): bool
    {
        if ($this->duplicateBlocks($duplicateConfirmed)) {
            return false;
        }

        return $this->firstTry?->blocks($resolution) === true;
    }
}
