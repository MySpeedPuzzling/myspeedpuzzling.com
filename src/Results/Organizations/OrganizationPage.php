<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Organizations;

use SpeedPuzzling\Web\Results\EventsPage\AgendaMonth;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveYear;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * Everything the organization page renders below its header (OrganizationPageBuilder,
 * docs/features/organizations/README.md "Organization page"): "Coming up" (live, then by month, then ongoing, then
 * without a date), "What we run" (a card per series, then the coming one-time events) and the past by year.
 */
readonly final class OrganizationPage
{
    /**
     * @param list<AgendaRow> $live occurrences running today
     * @param list<AgendaMonth> $comingUp upcoming occurrences by month
     * @param list<AgendaRow> $ongoing long spans running now (never live)
     * @param list<AgendaRow> $dateNotSet editions without a date and one-time events whose date is to be announced, by name
     * @param list<OrganizationSeriesCard> $seriesCards next date first, then the last one, then none - each by date, then name
     * @param list<OrganizationEventCard> $eventCards one-time events not over yet, by date (undated last), then name
     * @param list<ArchiveYear> $pastYears one line per past occurrence, newest year and line first
     */
    public function __construct(
        // `[flag] Region, Country` of the header - null without either
        public null|Place $place,
        public array $live,
        public array $comingUp,
        public array $ongoing,
        public array $dateNotSet,
        public array $seriesCards,
        public array $eventCards,
        public array $pastYears,
        // null unless the organization is publicly visible
        public null|FollowTarget $followTarget,
        public bool $following,
    ) {
    }

    /**
     * The rows of "Coming up"
     */
    public function comingCount(): int
    {
        $count = count($this->live) + count($this->ongoing) + count($this->dateNotSet);

        foreach ($this->comingUp as $month) {
            $count += count($month->rows);
        }

        return $count;
    }

    public function pastCount(): int
    {
        $count = 0;

        foreach ($this->pastYears as $year) {
            $count += count($year->lines);
        }

        return $count;
    }

    /**
     * Nothing to show at all: no series, no event, no past
     */
    public function isEmpty(): bool
    {
        return $this->comingCount() === 0
            && $this->seriesCards === []
            && $this->eventCards === []
            && $this->pastYears === [];
    }
}
