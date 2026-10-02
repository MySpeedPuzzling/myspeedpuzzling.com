<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the "same time already saved" check says about a result about to be saved
 * (docs/features/duplicate-results.md, Layer 2):
 *
 * - same day: somebody of the result already has a result of the puzzle with exactly this time from the same day.
 *   Most such pairs are a result saved twice, so saving needs a confirmation ("It's another solve, save it");
 * - other day: the same time from another day - mostly a genuine repeat, an information line only.
 *
 * An edit that changes neither the time, the day nor the people is tolerated: it did not create the twin, and a
 * confirmation would wrongly record an old copy as a real second solve. The lines are shown, nothing is asked.
 */
readonly final class DuplicateAssessment
{
    /**
     * @param list<DuplicateNoticeLine> $sameDayLines
     * @param list<DuplicateNoticeLine> $otherDayLines
     */
    public function __construct(
        public int $secondsToSolve,
        public array $sameDayLines,
        public array $otherDayLines,
        public bool $tolerated = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sameDayLines === [] && $this->otherDayLines === [];
    }

    public function needsConfirmation(): bool
    {
        return $this->sameDayLines !== [] && $this->tolerated === false;
    }

    public function blocks(bool $confirmed): bool
    {
        return $this->needsConfirmation() && $confirmed === false;
    }
}
