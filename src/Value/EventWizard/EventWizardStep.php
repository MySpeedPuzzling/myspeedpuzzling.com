<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * One step of the event creation wizard - its page (one single-action controller in src/Controller/EventWizard/ each)
 * and its name on the step rail. Which steps a path has, in which order, is EventWizardPath::steps(); whether a step is
 * done is derived from the draft (EventWizardSteps). A later step type (Structure, Puzzles, Dates, Extras) is a new case
 * here with its route and label, then listed in the paths that have it.
 */
enum EventWizardStep: string
{
    case Basics = 'basics';
    case Puzzle = 'puzzle';
    // Opt-in features as plain switches (W6, W-creation-wizard-research.md §3.8) - the last step before Review on every
    // path that has it
    case Extras = 'extras';
    // Several rounds (W5): the rounds from a template, then their puzzles in one grid
    case Structure = 'structure';
    case Puzzles = 'puzzles';
    case Review = 'review';
    // The series path (W3) - its pages take `seriesId`: the draft is a series, not an event
    case SeriesBasics = 'series_basics';
    case Dates = 'dates';
    case SeriesReview = 'series_review';

    /**
     * The step's route - every one but a new event's Basics takes `competitionId`, the series path's `seriesId`
     * (EventWizardProgress::routeParameters()).
     */
    public function route(): string
    {
        return match ($this) {
            self::Basics => 'event_wizard_basics_edit',
            self::Puzzle => 'event_wizard_puzzle',
            self::Extras => 'event_wizard_extras',
            self::Structure => 'event_wizard_structure',
            self::Puzzles => 'event_wizard_puzzles',
            self::Review => 'event_wizard_review',
            self::SeriesBasics => 'event_wizard_series_basics_edit',
            self::Dates => 'event_wizard_series_dates',
            self::SeriesReview => 'event_wizard_series_review',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            // The same words as the event's steps
            self::SeriesBasics => 'event_wizard.step.basics',
            self::SeriesReview => 'event_wizard.step.review',
            self::Dates => 'event_wizard_series.step.dates',
            default => 'event_wizard.step.' . $this->value,
        };
    }
}
