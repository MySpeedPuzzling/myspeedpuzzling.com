<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the add/edit-time form shows of "I didn't finish within the time limit" when it is rendered
 * (UnfinishedResultForm::state(), docs/features/competitions-management/unfinished-results-plan.md "Refined 2026-11-16")
 * - correct without JavaScript (a deep link, the edit form, a refused submit); unfinished_result_controller.js takes
 * over from it while the form is filled in, by the same rules.
 */
readonly final class UnfinishedFormState
{
    public function __construct(
        // The round with a time limit the form's competition + puzzle + solo/pair/team resolve to - null = none
        public null|UnfinishedRoundHint $round,
        // The switch is on (Speed Puzzling only)
        public bool $on,
        // The time typed into the form (the total when the switch is on), null = none
        public null|int $typedSeconds,
        // The puzzle's piece count for "/ 500 · 21 pieces left" - null = not known
        public null|int $piecesTotal,
        // The kill switch (UNFINISHED_RESULTS_FORM) - off: nothing is asked any more, an unfinished result keeps its switch
        public bool $enabled,
    ) {
    }

    /**
     * The switch is shown: always while it is on, else when a round with a limit is known and the time is empty or over
     * it - a time within the limit obviously finished
     */
    public function asks(): bool
    {
        return $this->on || ($this->round !== null && $this->round->asksFor($this->typedSeconds ?? 0));
    }

    /**
     * The time is over the round's limit and the switch is off: suggest it - never blocking (some events let people
     * finish)
     */
    public function suggests(): bool
    {
        return $this->on === false
            && $this->round !== null
            && $this->typedSeconds !== null
            && $this->typedSeconds > $this->round->limitSeconds();
    }
}
