<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * The answers of the wizard's first question, "What are you organizing?" (W-creation-wizard-research.md §3.1). Where each
 * one leads is decided in one place, EventWizardEntryController::target(): the one-round path opens the wizard; several
 * rounds (until W5) opens the classic form; a series opens the series path (W3); "something else" is the classic form for
 * good.
 */
enum EventWizardEntryChoice: string
{
    case OneRound = 'one_round';
    case SeveralRounds = 'several_rounds';
    case Series = 'series';
    case SomethingElse = 'other';

    /**
     * The wizard path the answer starts - null = no wizard path (the classic form)
     */
    public function path(): null|EventWizardPath
    {
        return match ($this) {
            self::OneRound => EventWizardPath::OneRound,
            self::SeveralRounds => EventWizardPath::SeveralRounds,
            self::Series => EventWizardPath::Series,
            self::SomethingElse => null,
        };
    }
}
