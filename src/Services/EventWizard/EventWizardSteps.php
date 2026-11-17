<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Results\EventWizardState;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;

/**
 * The one place that knows the steps of the event creation wizard's paths and when a step is done
 * (W-creation-wizard-research.md §3.2, §4 "Resume needs no storage"). A step is done when the draft holds what it asks:
 *
 * - Basics: the draft exists (it is created by Basics);
 * - Puzzle (one round): the round has a puzzle - "Decide later" leaves it not done, so resuming comes back to it;
 * - Review: never - publishing ends the wizard.
 *
 * A later step (W3 Dates, W5 Structure/Puzzles, W6 Extras) adds its case to EventWizardStep, its place to
 * EventWizardPath::steps() and its "done" rule to done() below.
 */
readonly final class EventWizardSteps
{
    /**
     * Before the draft exists: a new event's Basics on the chosen path.
     */
    public function forNewEvent(EventWizardPath $path): EventWizardProgress
    {
        return new EventWizardProgress(
            competitionId: null,
            path: $path,
            steps: $path->steps(),
            doneSteps: [],
        );
    }

    public function of(EventWizardState $state): EventWizardProgress
    {
        $path = EventWizardPath::of($state->isEdition, $state->roundsCount);
        $steps = $path->steps();

        return new EventWizardProgress(
            competitionId: $state->competitionId,
            path: $path,
            steps: $steps,
            doneSteps: array_values(array_filter($steps, static fn (EventWizardStep $step): bool => self::done($step, $state))),
            state: $state,
        );
    }

    private static function done(EventWizardStep $step, EventWizardState $state): bool
    {
        return match ($step) {
            EventWizardStep::Basics => true,
            EventWizardStep::Puzzle => $state->singleRoundId !== null && $state->roundPuzzlesCount > 0,
            EventWizardStep::Review => false,
        };
    }
}
