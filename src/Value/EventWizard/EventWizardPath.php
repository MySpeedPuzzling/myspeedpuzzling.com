<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * What the organiser is setting up - the branch of the event creation wizard (W-creation-wizard-research.md §3.2). Not
 * stored anywhere: of a draft it is derived from its data (of()), so a draft resumes on the right path whoever made it.
 *
 * W1 builds the one-round path, W5 several rounds; the series path (W3) answers with the classic pages until its
 * steps exist - steps() lists only the steps that exist, and EventWizardSteps sends a draft of a path without steps of
 * its own to its edit page.
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

    /**
     * An edition belongs to a series; two rounds or more are "several rounds" - and so is a draft without any round: the
     * one-round path creates its round with the draft (StartEventWizardDraft), so a draft without one is a several-rounds
     * draft between Basics and Structure (or "not sure yet" - an empty round list), or a classic draft, for which the
     * Structure step is the right next question too. Exactly one round is the one-round path (the Structure step never
     * saves exactly one round, EventWizardStructureController).
     */
    public static function of(bool $isEdition, int $roundsCount): self
    {
        return match (true) {
            $isEdition => self::Series,
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
            self::Backfill => [EventWizardStep::PastEvent, EventWizardStep::PastPuzzles, EventWizardStep::LinkResults, EventWizardStep::PastDone],
        };
    }
}
