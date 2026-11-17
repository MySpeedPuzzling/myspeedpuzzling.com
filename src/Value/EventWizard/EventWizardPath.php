<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * What the organiser is setting up - the branch of the event creation wizard (W-creation-wizard-research.md §3.2). Not
 * stored anywhere: of a draft it is derived from its data (of()), so a draft resumes on the right path whoever made it.
 *
 * W1 builds the one-round path; the series path (W3) and several rounds (W5) answer with the classic pages until their
 * steps exist - steps() lists only the steps that exist, and EventWizardSteps sends a draft of a path without steps of
 * its own to its edit page.
 */
enum EventWizardPath: string
{
    case OneRound = 'one_round';
    case SeveralRounds = 'several_rounds';
    case Series = 'series';

    /**
     * An edition belongs to a series; two rounds or more are "several rounds"; anything else - no round yet or one -
     * is the one-round path.
     */
    public static function of(bool $isEdition, int $roundsCount): self
    {
        return match (true) {
            $isEdition => self::Series,
            $roundsCount >= 2 => self::SeveralRounds,
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
            // A draft series (W3) - its editions are not set up in the wizard (EventWizardSteps::of()). No Extras: every
            // feature it switches (registration, links, judges, the toolkit's settings and displays) belongs to one
            // date (an edition), not to the series - each date's edit page and "Get ready" checklist have them
            self::Series => [EventWizardStep::SeriesBasics, EventWizardStep::Dates, EventWizardStep::SeriesReview],
            self::SeveralRounds => [],
        };
    }
}
