<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The creation wizard's backfill path (docs/features/competitions-management/creation-wizard.md "Backfill"): an event
 * that already happened, added to link results to it - in one transaction, through the existing messages: a new
 * organization (AddOrganization, waiting for approval) and/or a new series (AddCompetitionSeries) when the player said
 * it belongs to one, then the event itself - a one-time event (AddCompetition) or a date of a series (AddEdition).
 * Never a draft: submitted for approval right away like the classic form (the same admin e-mail), unless
 * OrganizationApprovalPolicy approves it at once.
 */
readonly final class CreatePastEvent
{
    public function __construct(
        public null|AddOrganization $organization,
        public null|AddCompetitionSeries $series,
        public null|AddCompetition $competition,
        public null|AddEdition $edition,
    ) {
        if (($competition === null) === ($edition === null)) {
            throw new \InvalidArgumentException('A past event is either a one-time event or a date of a series.');
        }

        if ($competition?->isDraft === true || $edition?->isDraft === true || $series?->isDraft === true || $organization?->isDraft === true) {
            throw new \InvalidArgumentException('A past event is submitted at once - never a draft.');
        }

        if ($series !== null && ($edition === null || $edition->seriesId !== $series->seriesId->toString())) {
            throw new \InvalidArgumentException('A new series gets the event as its date.');
        }
    }

    public function eventId(): string
    {
        if ($this->competition !== null) {
            return $this->competition->competitionId->toString();
        }

        return $this->edition !== null ? $this->edition->competitionId->toString() : '';
    }
}
