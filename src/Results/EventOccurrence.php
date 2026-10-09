<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\OccurrenceSession;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * One dated (or not yet dated) occurrence on the events page: a one-time event or an edition of a series
 * (docs/features/events-page/README.md), or one session of one when its rounds fall on separate days (`session`). Dates
 * are date-only values at 00:00 UTC (OccurrenceDates).
 */
readonly final class EventOccurrence
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $slug,
        public null|string $logo = null,
        public null|string $seriesId = null,
        public null|string $seriesName = null,
        public null|string $seriesSlug = null,
        public null|string $location = null,
        public null|CountryCode $countryCode = null,
        public bool $isOnline = false,
        public null|DateTimeImmutable $startDate = null,
        public null|DateTimeImmutable $endDate = null,
        public int $roundCount = 0,
        public bool $hasRegistrationLink = false,
        public bool $registrationManaged = false,
        public null|int $capacity = null,
        public null|DateTimeImmutable $registrationOpensAt = null,
        public null|DateTimeImmutable $registrationClosesAt = null,
        public null|string $registrationTimezone = null,
        public bool $hasResults = false,
        public bool $isPublic = true,
        // one of several sessions (rounds on separate days, OccurrenceDates::sessions()); null for the common case
        public null|OccurrenceSession $session = null,
        // the day of the last round dating it (OccurrenceDates::$lastRoundDay); null without rounds
        public null|DateTimeImmutable $lastRoundDay = null,
        // the first round of the occurrence or session (OccurrenceDates::$firstRound) - its start time; null without rounds
        public null|OccurrenceRound $firstRound = null,
        // the external registration link, only while registration is not managed here
        public null|string $registrationLink = null,
        // its organization: a one-time event's own, an edition's series' (docs/features/organizations/README.md)
        public null|OrganizationRef $organization = null,
        // "Who can enter": its own, else its series'
        public null|string $eligibility = null,
        // a draft itself, or an edition of a draft series - only its team ever gets such a row
        public bool $isDraft = false,
        // the zone its days are in (OccurrenceDates::$zone) - its "today" is today there
        public null|string $zone = null,
    ) {
    }

    public function isEdition(): bool
    {
        return $this->seriesId !== null;
    }

    /**
     * @param DateTimeImmutable $now the instant - its day is read in the occurrence's own zone
     */
    public function status(DateTimeImmutable $now): EventOccurrenceStatus
    {
        return $this->dates()->status($now, $this->isEdition(), $this->isOnline);
    }

    public function dates(): OccurrenceDates
    {
        return new OccurrenceDates($this->startDate, $this->endDate, $this->lastRoundDay, $this->session, $this->firstRound, $this->zone);
    }

    public function isLongRunning(): bool
    {
        if ($this->startDate === null || $this->endDate === null) {
            return false;
        }

        return (int) $this->startDate->diff($this->endDate)->days > EventsPageBuilder::LONG_RUN_DAYS;
    }

    /**
     * The edition's own name - null for a one-time event and when it equals the series name (case-insensitive, as
     * CompetitionReference::displayName()).
     */
    public function editionName(): null|string
    {
        if ($this->seriesName === null || mb_strtolower($this->seriesName) === mb_strtolower($this->name)) {
            return null;
        }

        return $this->name;
    }

    /**
     * The session's label (its one round's name) - null when it only repeats the name or the edition's name
     */
    public function sessionLabel(): null|string
    {
        $label = trim((string) $this->session?->label);

        if ($label === '') {
            return null;
        }

        foreach ([$this->isEdition() ? $this->seriesName : $this->name, $this->editionName()] as $name) {
            if ($name !== null && mb_strtolower(trim($name)) === mb_strtolower($label)) {
                return null;
            }
        }

        return $label;
    }

    /**
     * The line under the name: the edition's own name and the session's label, as far as there are any -
     * "Season One · Sprint 3".
     */
    public function subtitle(): null|string
    {
        $parts = array_values(array_filter([$this->editionName(), $this->sessionLabel()], static fn (null|string $part): bool => $part !== null));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Only for an event whose registration MySpeedPuzzling manages. Without a closing time the window closes when the
     * occurrence is over (its last day in the registration zone).
     */
    public function registrationAvailability(DateTimeImmutable $now): null|RegistrationAvailability
    {
        if ($this->registrationManaged === false) {
            return null;
        }

        if ($this->isPublic === false) {
            return RegistrationAvailability::NotPublic;
        }

        $zone = RoundTimezone::resolve($this->registrationTimezone, $this->countryCode?->name);

        return RegistrationAvailability::ofWindow(
            $now,
            $this->registrationOpensAt,
            $this->registrationClosesAt,
            RegistrationAvailability::eventEndsAt($this->startDate, $this->endDate, $zone),
        );
    }

    public function registrationZone(): string
    {
        return RoundTimezone::resolve($this->registrationTimezone, $this->countryCode?->name);
    }

    public function reference(): CompetitionReference
    {
        return new CompetitionReference(
            name: $this->name,
            slug: $this->slug,
            seriesName: $this->seriesName,
            seriesSlug: $this->seriesSlug,
        );
    }
}
