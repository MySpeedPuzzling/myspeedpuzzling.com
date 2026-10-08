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
 * directly, or a series - waiting for approval and rejected ones included.
 */
readonly final class OrganizedEvent
{
    public const string KIND_EVENT = 'event';
    public const string KIND_EDITION = 'edition';
    public const string KIND_SERIES = 'series';

    /**
     * @param 'event'|'edition'|'series' $kind
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
        public bool $isApproved = false,
        public null|string $rejectionReason = null,
        public bool $isRejected = false,
        public int $editionCount = 0,
        // Series: the start of the earliest edition that is not over (live or upcoming)
        public null|DateTimeImmutable $nextEditionDate = null,
        // Series: the start of the latest edition that is over
        public null|DateTimeImmutable $lastEditionDate = null,
    ) {
    }

    public function isSeries(): bool
    {
        return $this->kind === self::KIND_SERIES;
    }

    public function badge(DateTimeImmutable $today): OrganizerBadge
    {
        if ($this->isRejected) {
            return OrganizerBadge::Rejected;
        }

        if ($this->isApproved === false) {
            return OrganizerBadge::WaitingForApproval;
        }

        if ($this->isSeries()) {
            if ($this->nextEditionDate !== null) {
                return $this->nextEditionDate <= OccurrenceDates::today($today) ? OrganizerBadge::Live : OrganizerBadge::Upcoming;
            }

            return $this->lastEditionDate !== null ? OrganizerBadge::Past : OrganizerBadge::DateNotSet;
        }

        $status = new OccurrenceDates($this->startDate, $this->endDate, $this->roundCount > 0)->status($today, $this->kind === self::KIND_EDITION, $this->isOnline);

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
