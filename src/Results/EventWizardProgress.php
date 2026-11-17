<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;

/**
 * A draft's way through the event creation wizard (EventWizardSteps): its path, the path's steps in order and which are
 * done - derived from the draft's data, nothing stored. The step rail and resuming ("Continue setting up") read it.
 */
readonly final class EventWizardProgress
{
    /**
     * @param list<EventWizardStep> $steps
     * @param list<EventWizardStep> $doneSteps
     */
    public function __construct(
        public null|string $competitionId,
        public EventWizardPath $path,
        public array $steps,
        public array $doneSteps,
        // What it was derived from - null before the draft exists
        public null|EventWizardState $state = null,
    ) {
    }

    public function isDone(EventWizardStep $step): bool
    {
        return in_array($step, $this->doneSteps, true);
    }

    public function has(EventWizardStep $step): bool
    {
        return in_array($step, $this->steps, true);
    }

    /**
     * Where a draft resumes: the first step not done yet (Review once everything else is).
     */
    public function firstNotDone(): null|EventWizardStep
    {
        foreach ($this->steps as $step) {
            if ($this->isDone($step) === false) {
                return $step;
            }
        }

        return $this->steps[array_key_last($this->steps) ?? 0] ?? null;
    }

    /**
     * 1-based position of the step on its path ("Step 2 of 3")
     */
    public function position(EventWizardStep $step): int
    {
        $index = array_search($step, $this->steps, true);

        return $index === false ? 0 : $index + 1;
    }

    /**
     * The step after this one - null after the last
     */
    public function next(EventWizardStep $step): null|EventWizardStep
    {
        $position = $this->position($step);

        return $this->steps[$position] ?? null;
    }

    /**
     * The step before this one - null on the first
     */
    public function previous(EventWizardStep $step): null|EventWizardStep
    {
        $position = $this->position($step);

        return $position >= 2 ? $this->steps[$position - 2] : null;
    }
}
