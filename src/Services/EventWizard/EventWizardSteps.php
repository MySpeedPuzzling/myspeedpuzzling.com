<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Results\EventWizardSeriesState;
use SpeedPuzzling\Web\Results\EventWizardState;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;

/**
 * The one place that knows the steps of the event creation wizard's paths and when a step is done
 * (W-creation-wizard-research.md §3.2, §4 "Resume needs no storage"). A step is done when the draft holds what it asks:
 *
 * - Basics: the draft exists (it is created by Basics);
 * - Puzzle (one round): the round has a puzzle - "Decide later" leaves it not done, so resuming comes back to it;
 * - Extras: one of its features is on (EventWizardState::$extrasOn) - nothing switched on leaves it not done, so resuming
 *   shows it once more (one click: "Continue");
 * - Review: never - publishing ends the wizard.
 *
 * The series path (W3) works on a draft series (ofSeries()): Basics done once it exists, Dates once it has an edition.
 * An edition itself is not set up in the wizard - of() gives it no steps (its edit page).
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
        // An edition belongs to its series' wizard (ofSeries()), never one of its own
        $steps = $state->isEdition ? [] : $path->steps();

        return new EventWizardProgress(
            competitionId: $state->competitionId,
            path: $path,
            steps: $steps,
            doneSteps: array_values(array_filter($steps, static fn (EventWizardStep $step): bool => self::done($step, $state))),
            state: $state,
        );
    }

    public function ofSeries(EventWizardSeriesState $state): EventWizardProgress
    {
        $steps = EventWizardPath::Series->steps();

        return new EventWizardProgress(
            competitionId: null,
            path: EventWizardPath::Series,
            steps: $steps,
            doneSteps: array_values(array_filter($steps, static fn (EventWizardStep $step): bool => match ($step) {
                EventWizardStep::SeriesBasics => true,
                EventWizardStep::Dates => $state->editionsCount > 0,
                default => false,
            })),
            seriesId: $state->seriesId,
        );
    }

    private static function done(EventWizardStep $step, EventWizardState $state): bool
    {
        return match ($step) {
            EventWizardStep::Basics => true,
            EventWizardStep::Puzzle => $state->singleRoundId !== null && $state->roundPuzzlesCount > 0,
            EventWizardStep::Extras => $state->extrasOn,
            EventWizardStep::Review => false,
            // The series path's steps are never an event's (ofSeries())
            EventWizardStep::SeriesBasics, EventWizardStep::Dates, EventWizardStep::SeriesReview => false,
        };
    }
}
