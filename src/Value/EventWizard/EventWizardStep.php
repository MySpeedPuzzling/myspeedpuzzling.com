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
    case Review = 'review';

    /**
     * The step's route - every one but a new event's Basics takes `competitionId`.
     */
    public function route(): string
    {
        return match ($this) {
            self::Basics => 'event_wizard_basics_edit',
            self::Puzzle => 'event_wizard_puzzle',
            self::Review => 'event_wizard_review',
        };
    }

    public function labelKey(): string
    {
        return 'event_wizard.step.' . $this->value;
    }
}
