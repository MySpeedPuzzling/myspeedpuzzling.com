<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The add/edit form's pace check of one entry (docs/features/suspicious-time-review.md, "Catch it while typing"): what
 * the classifier says, and the key of the values it judged (SuspiciousTimeFormCheck::confirmationKey()). "Yes, it's
 * right" carries the key back and counts only for exactly these values - an answer given before the time, the puzzle,
 * the day or the people changed is no answer to the new entry.
 */
readonly final class PaceFormCheck
{
    public function __construct(
        public SuspicionAssessment $assessment,
        public string $confirmationKey,
    ) {
    }

    public function isRaised(): bool
    {
        return $this->assessment->isRaised();
    }

    /**
     * $answer = the form's pace_confirmed: "Yes, it's right" chosen for exactly these values.
     */
    public function isConfirmedBy(string $answer): bool
    {
        return $this->assessment->isRaised() && $answer !== '' && hash_equals($this->confirmationKey, $answer);
    }
}
