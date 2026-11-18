<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * What the organiser is setting up - the branch of the event creation wizard (W-creation-wizard-research.md §3.2). Not
 * stored anywhere: of a draft it is derived from its data (of()), so a draft resumes on the right path whoever made it.
 *
 * W1 builds the one-round path, W5 several rounds, W3 the series path; "Something else" (a meetup, a swap, a workshop -
 * no rounds) is the calendar path since the classic create form was retired (2026-11-18). steps() lists only the steps
 * that exist, and EventWizardSteps sends a draft of a path without steps of its own to its edit page.
 */
enum EventWizardPath: string
{
    case OneRound = 'one_round';
    case SeveralRounds = 'several_rounds';
    case Series = 'series';
    // An event that already happened, added to link results to it (creation-wizard.md "Backfill"). Never derived from a
    // draft (of()): the backfill event is submitted at once, not a draft - its later steps work on any event the player
    // may edit (Puzzles) or link to (Link my results), reached from the step before
    case Backfill = 'backfill';
    // "Something else" - an event on the calendar without rounds (a meetup, a puzzle swap, a workshop, a marathon): what
    // kind of event it is (its event types, asked on Basics, never "Competition") is what tells its draft apart from a
    // several-rounds draft that has no round yet (of())
    case Calendar = 'calendar';

    /**
     * An edition belongs to a series; two rounds or more are "several rounds" - and so is a draft without any round: the
     * one-round path creates its round with the draft (StartEventWizardDraft), so a draft without one is a several-rounds
     * draft between Basics and Structure (or "not sure yet" - an empty round list), or a classic draft, for which the
     * Structure step is the right next question too. Exactly one round is the one-round path (the Structure step never
     * saves exactly one round, EventWizardStructureController). A draft without any round whose event types are set and
     * are not "Competition" (`$calendarEvent`, GetEventWizardState) is the calendar path - the classic form's no-round
     * drafts of a meetup or a workshop included.
     */
    public static function of(bool $isEdition, int $roundsCount, bool $calendarEvent = false): self
    {
        return match (true) {
            $isEdition => self::Series,
            $roundsCount === 0 && $calendarEvent => self::Calendar,
            $roundsCount >= 2, $roundsCount === 0 => self::SeveralRounds,
            default => self::OneRound,
        };
    }

    /**
     * The wizard's steps of this path, in order. Empty = the path has no wizard pages yet (its drafts are set up on the
     * classic pages). Extras (W6) is the step before Review - a path that gets it lists it there; the step itself works
     * on any draft event (an edition included), the rows it offers follow the event's data (EventWizardExtrasController).
     *
     * @return list<EventWizardStep>
     */
    public function steps(): array
    {
        return match ($this) {
            self::OneRound => [EventWizardStep::Basics, EventWizardStep::Puzzle, EventWizardStep::Extras, EventWizardStep::Review],
            self::SeveralRounds => [EventWizardStep::Basics, EventWizardStep::Structure, EventWizardStep::Puzzles, EventWizardStep::Extras, EventWizardStep::Review],
            // A draft series (W3) - its editions are not set up in the wizard (EventWizardSteps::of()). No Extras: every
            // feature it switches (registration, links, judges, the toolkit's settings and displays) belongs to one
            // date (an edition), not to the series - each date's edit page and "Get ready" checklist have them
            self::Series => [EventWizardStep::SeriesBasics, EventWizardStep::Dates, EventWizardStep::SeriesReview],
            // No Extras: its switches (judges, name tags, displays, standings) are a competition's - registration and
            // links are on the event's edit page and its "Get ready" checklist
            self::Calendar => [EventWizardStep::Basics, EventWizardStep::Review],
            self::Backfill => [EventWizardStep::PastEvent, EventWizardStep::PastPuzzles, EventWizardStep::LinkResults, EventWizardStep::PastDone],
        };
    }
}
