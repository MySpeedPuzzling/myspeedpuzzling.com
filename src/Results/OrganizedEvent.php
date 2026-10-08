<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OrganizerBadge;

/**
 * One item of "You organize" (docs/features/events-page/README.md): a one-time event, an edition the viewer organises
 * directly, a series or an organization (docs/features/organizations/README.md) - drafts, waiting for approval and
 * rejected ones included.
 */
readonly final class OrganizedEvent
{
    public const string KIND_EVENT = 'event';
    public const string KIND_EDITION = 'edition';
    public const string KIND_SERIES = 'series';
    public const string KIND_ORGANIZATION = 'organization';

    /**
     * @param 'event'|'edition'|'series'|'organization' $kind
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
        public null|string $seriesId = null,
        public null|string $seriesName = null,
        public null|string $slug = null,
        public null|string $seriesSlug = null,
        public bool $isOnline = false,
        public null|string $location = null,
        public null|CountryCode $countryCode = null,
        public null|DateTimeImmutable $startDate = null,
        public null|DateTimeImmutable $endDate = null,
        public int $roundCount = 0,
        // the day of the last round dating it (OccurrenceDates::$lastRoundDay)
        public null|DateTimeImmutable $lastRoundDay = null,
        public bool $isApproved = false,
        public null|string $rejectionReason = null,
        public bool $isRejected = false,
        public int $editionCount = 0,
        // Series: the start of the earliest edition that is not over (live or upcoming)
        public null|DateTimeImmutable $nextEditionDate = null,
        // Series: the start of the latest edition that is over
        public null|DateTimeImmutable $lastEditionDate = null,
        // A draft itself - for an edition also when its series is one
        public bool $isDraft = false,
        // The organization it is under (a one-time event's own, a series' or an edition's series'), null for an
        // organization itself
        public null|string $organizationId = null,
        // Organization: its series and one-time events
        public int $seriesCount = 0,
        public int $eventCount = 0,
    ) {
    }

    public function isSeries(): bool
    {
        return $this->kind === self::KIND_SERIES;
    }

    public function isOrganization(): bool
    {
        return $this->kind === self::KIND_ORGANIZATION;
    }

    public function badge(DateTimeImmutable $today): OrganizerBadge
    {
        if ($this->isRejected) {
            return OrganizerBadge::Rejected;
        }

        // A draft that also waits for approval shows Draft - it is submitted by publishing it
        if ($this->isDraft) {
            return OrganizerBadge::Draft;
        }

        if ($this->isApproved === false) {
            return OrganizerBadge::WaitingForApproval;
        }

        // An approved, published organization has no dates of its own
        if ($this->isOrganization()) {
            return OrganizerBadge::DateNotSet;
        }

        if ($this->isSeries()) {
            if ($this->nextEditionDate !== null) {
                return $this->nextEditionDate <= OccurrenceDates::today($today) ? OrganizerBadge::Live : OrganizerBadge::Upcoming;
            }

            return $this->lastEditionDate !== null ? OrganizerBadge::Past : OrganizerBadge::DateNotSet;
        }

        $status = new OccurrenceDates($this->startDate, $this->endDate, $this->lastRoundDay)->status($today, $this->kind === self::KIND_EDITION, $this->isOnline);

        return match ($status) {
            EventOccurrenceStatus::Live => OrganizerBadge::Live,
            // A long span without rounds that is running - for its organiser it is live
            EventOccurrenceStatus::Ongoing => $this->startDate !== null ? OrganizerBadge::Live : OrganizerBadge::DateNotSet,
            EventOccurrenceStatus::Upcoming => OrganizerBadge::Upcoming,
            EventOccurrenceStatus::Past => OrganizerBadge::Past,
            default => OrganizerBadge::DateNotSet,
        };
    }

    public function reference(): CompetitionReference
    {
        // An organization's page is organization_detail - the reference only names it (C links it)
        if ($this->isOrganization()) {
            return new CompetitionReference(name: $this->name, slug: null);
        }

        if ($this->isSeries()) {
            return new CompetitionReference(name: $this->name, slug: $this->slug, isSeries: true);
        }

        return new CompetitionReference(
            name: $this->name,
            slug: $this->slug,
            seriesName: $this->seriesName,
            seriesSlug: $this->seriesSlug,
        );
    }
}
