<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * The answers of the wizard's first question, "What do you want to add?" (W-creation-wizard-research.md §3.1). Where each
 * one leads is decided in one place, EventWizardEntryController::target(): the one-round path and several rounds (W5) open
 * the wizard; a series opens the series path (W3); "something else" opens the calendar path (no rounds - the classic
 * create form it used to open was retired 2026-11-18); an event that already
 * happened opens the backfill path (creation-wizard.md "Backfill" - players who did not organise it, linking their
 * results) - listed first, it is the most common real use.
 */
enum EventWizardEntryChoice: string
{
    case PastEvent = 'past';
    case OneRound = 'one_round';
    case SeveralRounds = 'several_rounds';
    case Series = 'series';
    case SomethingElse = 'other';

    /**
     * The wizard path the answer starts
     */
    public function path(): EventWizardPath
    {
        return match ($this) {
            self::OneRound => EventWizardPath::OneRound,
            self::SeveralRounds => EventWizardPath::SeveralRounds,
            self::Series => EventWizardPath::Series,
            self::SomethingElse => EventWizardPath::Calendar,
            self::PastEvent => EventWizardPath::Backfill,
        };
    }
}
