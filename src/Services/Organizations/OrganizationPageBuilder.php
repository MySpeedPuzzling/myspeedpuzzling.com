<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Organizations;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\AgendaMonth;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveYear;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Results\Organizations\OrganizationEventCard;
use SpeedPuzzling\Web\Results\Organizations\OrganizationPage;
use SpeedPuzzling\Web\Results\Organizations\OrganizationSeriesCard;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RowContext;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The organization page (docs/features/organizations/README.md "Organization page") from the occurrences of its series
 * and one-time events (GetEventOccurrences::forOrganization()) and its series (GetEventSeriesDirectory::forOrganization())
 * - the series page's rows (EventRowFactory, RowContext::OrganizationPage: named as on the events page, "Recurring"
 * kept, no star on a row) and the same month headers and past by year. Pure apart from EventUrls; each rule has a test
 * in OrganizationPageBuilderTest.
 *
 * @phpstan-type Item array{occurrence: EventOccurrence, status: EventOccurrenceStatus, id: int}
 */
readonly final class OrganizationPageBuilder
{
    public function __construct(
        private EventRowFactory $rows,
        private EventUrls $urls,
    ) {
    }

    /**
     * @param list<EventOccurrence> $occurrences ordered by start (undated last), then name
     * @param list<EventSeriesRow> $series
     * @param array<string, int> $goingCounts competition id (lower case) => spots taken
     */
    public function build(
        OrganizationDetail $organization,
        array $occurrences,
        array $series,
        array $goingCounts,
        null|EventsViewerData $viewer,
        DateTimeImmutable $now,
        string $locale,
    ): OrganizationPage {
        $day = OccurrenceDates::today($now);
        $scope = EventsScope::everywhere();

        /** @var list<Item> $items */
        $items = [];

        foreach ($occurrences as $occurrence) {
            // Today in the occurrence's own zone - $day (UTC) only counts the "In 3 days" labels
            $items[] = ['occurrence' => $occurrence, 'status' => $occurrence->status($now), 'id' => count($items)];
        }

        $rowOf = function (array $item) use ($goingCounts, $viewer, $scope, $now, $day, $locale): AgendaRow {
            /** @var Item $item */
            return $this->rows->row(
                $item['occurrence'],
                $item['status'],
                $item['id'],
                $goingCounts,
                $viewer,
                $scope,
                $now,
                $day,
                $locale,
                $item['occurrence']->logo,
                RowContext::OrganizationPage,
            );
        };

        $live = self::withStatus($items, EventOccurrenceStatus::Live);
        $upcoming = self::withStatus($items, EventOccurrenceStatus::Upcoming);
        $ongoing = self::withStatus($items, EventOccurrenceStatus::Ongoing);
        $dateNotSet = self::withStatus($items, EventOccurrenceStatus::DateNotSet, EventOccurrenceStatus::Tba);
        $past = self::withStatus($items, EventOccurrenceStatus::Past);

        usort($ongoing, self::compareByTitle(...));
        usort($dateNotSet, self::compareByTitle(...));

        $followTarget = $organization->isPublic() ? FollowTarget::organization($organization->id) : null;

        return new OrganizationPage(
            place: self::placeOf($organization->region, $organization->countryCode, $locale),
            live: array_map($rowOf, $live),
            comingUp: $this->months($upcoming, $rowOf),
            ongoing: array_map($rowOf, $ongoing),
            dateNotSet: array_map($rowOf, $dateNotSet),
            seriesCards: $this->seriesCards($series, $occurrences, $viewer, $now, $locale),
            eventCards: $this->eventCards($items, $viewer, $locale),
            pastYears: $this->pastYears($past, $locale),
            followTarget: $followTarget,
            following: $followTarget !== null && $viewer !== null && $viewer->follows($followTarget),
        );
    }

    /**
     * The date a series card (or a directory line) shows: the next upcoming one, else one live now, else a long span
     * running now, else the last one, else none - as the events page's series lines (EventsPageBuilder).
     *
     * @param list<EventOccurrence> $occurrences
     * @param DateTimeImmutable $now the instant - each occurrence reads its day in its own zone
     */
    public static function nextOf(array $occurrences, DateTimeImmutable $now): SeriesNext
    {
        $next = null;
        $live = null;
        $ongoing = null;
        $last = null;

        foreach ($occurrences as $occurrence) {
            $start = $occurrence->startDate;

            match ($occurrence->status($now)) {
                EventOccurrenceStatus::Upcoming => $next = $next === null || $start < $next ? $start : $next,
                EventOccurrenceStatus::Live => $live = $live === null || $start < $live ? $start : $live,
                EventOccurrenceStatus::Ongoing => $ongoing = $ongoing === null || $start < $ongoing ? $start : $ongoing,
                EventOccurrenceStatus::Past => $last = $last === null || $start > $last ? $start : $last,
                default => null,
            };
        }

        return match (true) {
            $next !== null => new SeriesNext(SeriesNext::NEXT, $next),
            $live !== null => new SeriesNext(SeriesNext::LIVE, $live),
            $ongoing !== null => new SeriesNext(SeriesNext::ONGOING, $ongoing),
            $last !== null => new SeriesNext(SeriesNext::LAST, $last),
            default => new SeriesNext(SeriesNext::NONE, null),
        };
    }

    /**
     * `[flag] Region, Country` - null without either
     */
    public static function placeOf(null|string $region, null|CountryCode $country, string $locale): null|Place
    {
        if (($region === null || trim($region) === '') && $country === null) {
            return null;
        }

        return EventRowFactory::place(false, $region, $country, $locale);
    }

    /**
     * Next (or live, or ongoing) first by date, then the last one (newest first), then none - each by name after that
     *
     * @param list<EventSeriesRow> $series
     * @param list<EventOccurrence> $occurrences
     *
     * @return list<OrganizationSeriesCard>
     */
    private function seriesCards(array $series, array $occurrences, null|EventsViewerData $viewer, DateTimeImmutable $now, string $locale): array
    {
        /** @var array<string, list<EventOccurrence>> $bySeries */
        $bySeries = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence->seriesId !== null) {
                $bySeries[strtolower($occurrence->seriesId)][] = $occurrence;
            }
        }

        $cards = [];

        foreach ($series as $row) {
            $editions = $bySeries[strtolower($row->id)] ?? [];
            // Sessions of one edition are one edition
            $editionIds = [];

            foreach ($editions as $edition) {
                $editionIds[strtolower($edition->competitionId)] = true;
            }

            $followTarget = $row->isPublic ? FollowTarget::series($row->id) : null;

            $cards[] = new OrganizationSeriesCard(
                seriesId: $row->id,
                name: $row->name,
                url: $this->urls->series($row->slug),
                place: EventRowFactory::place($row->isOnline, $row->location, $row->countryCode, $locale),
                isOnline: $row->isOnline,
                schedule: $row->schedule,
                eligibility: $row->eligibility,
                editionCount: count($editionIds),
                next: self::nextOf($editions, $now),
                followTarget: $followTarget,
                following: $followTarget !== null && $viewer !== null && $viewer->follows($followTarget),
                manage: new ManageRef(ManageRef::KIND_SERIES, $row->id, $row->name),
                isDraft: $row->isDraft,
                isPending: $row->isPublic === false && $row->isDraft === false,
            );
        }

        $rank = static fn (OrganizationSeriesCard $card): int => match ($card->next->type) {
            SeriesNext::LIVE, SeriesNext::NEXT, SeriesNext::ONGOING => 0,
            SeriesNext::LAST => 1,
            default => 2,
        };

        usort($cards, static function (OrganizationSeriesCard $a, OrganizationSeriesCard $b) use ($rank): int {
            $byRank = $rank($a) <=> $rank($b);

            if ($byRank !== 0) {
                return $byRank;
            }

            if ($a->next->date !== null && $b->next->date !== null && $a->next->date != $b->next->date) {
                return $rank($a) === 1 ? $b->next->date <=> $a->next->date : $a->next->date <=> $b->next->date;
            }

            return strcmp(SearchText::fold($a->name), SearchText::fold($b->name));
        });

        return $cards;
    }

    /**
     * The one-time events not over yet - one card per event (its sessions together), by its first day still to come
     * (undated last), then name
     *
     * @param list<Item> $items
     *
     * @return list<OrganizationEventCard>
     */
    private function eventCards(array $items, null|EventsViewerData $viewer, string $locale): array
    {
        /** @var array<string, list<Item>> $byEvent */
        $byEvent = [];

        foreach ($items as $item) {
            if ($item['occurrence']->isEdition()) {
                continue;
            }

            $byEvent[strtolower($item['occurrence']->competitionId)][] = $item;
        }

        $cards = [];

        foreach ($byEvent as $sessions) {
            $coming = array_values(array_filter($sessions, static fn (array $item): bool => $item['status'] !== EventOccurrenceStatus::Past));

            if ($coming === []) {
                continue;
            }

            // Ordered by start already (undated last) - the first session not over yet
            $first = $coming[0];
            $occurrence = $first['occurrence'];
            $lastDay = null;

            foreach ($sessions as $session) {
                $end = $session['occurrence']->endDate ?? $session['occurrence']->startDate;

                if ($end !== null && ($lastDay === null || $end > $lastDay)) {
                    $lastDay = $end;
                }
            }

            $from = $occurrence->startDate;
            $followTarget = $occurrence->isPublic ? FollowTarget::competition($occurrence->competitionId) : null;

            $cards[] = new OrganizationEventCard(
                competitionId: $occurrence->competitionId,
                name: $occurrence->name,
                url: $this->urls->occurrencePage($occurrence),
                place: EventRowFactory::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
                isOnline: $occurrence->isOnline,
                from: $from,
                to: $from !== null && $lastDay !== null && $lastDay->format('Y-m-d') !== $from->format('Y-m-d') ? $lastDay : null,
                status: $first['status'],
                eligibility: $occurrence->eligibility,
                followTarget: $followTarget,
                following: $followTarget !== null && $viewer !== null && $viewer->follows($followTarget),
                manage: new ManageRef(ManageRef::KIND_COMPETITION, $occurrence->competitionId, $occurrence->name),
                isDraft: $occurrence->isDraft,
                isPending: $occurrence->isPublic === false && $occurrence->isDraft === false,
            );
        }

        usort($cards, static function (OrganizationEventCard $a, OrganizationEventCard $b): int {
            if ($a->from === null || $b->from === null) {
                return ($a->from === null) <=> ($b->from === null)
                    ?: strcmp(SearchText::fold($a->name), SearchText::fold($b->name));
            }

            return $a->from <=> $b->from ?: strcmp(SearchText::fold($a->name), SearchText::fold($b->name));
        });

        return $cards;
    }

    /**
     * @param list<Item> $items upcoming, by start
     * @param callable(Item): AgendaRow $rowOf
     *
     * @return list<AgendaMonth>
     */
    private function months(array $items, callable $rowOf): array
    {
        /** @var array<string, list<AgendaRow>> $rows */
        $rows = [];
        /** @var array<string, DateTimeImmutable> $firstDays */
        $firstDays = [];

        foreach ($items as $item) {
            $start = $item['occurrence']->startDate;
            assert($start !== null);
            $key = $start->format('Y-m');
            $rows[$key][] = $rowOf($item);
            $firstDays[$key] ??= $start->modify('first day of this month');
        }

        $months = [];

        foreach ($rows as $key => $monthRows) {
            $firstDay = $firstDays[$key];
            $months[] = new AgendaMonth(
                year: (int) $firstDay->format('Y'),
                month: (int) $firstDay->format('n'),
                firstDay: $firstDay,
                rows: $monthRows,
                visibleCount: count($monthRows),
            );
        }

        return $months;
    }

    /**
     * One line per past occurrence (or session), by start year, newest year and line first - the series page's past
     *
     * @param list<Item> $past
     *
     * @return list<ArchiveYear>
     */
    private function pastYears(array $past, string $locale): array
    {
        /** @var array<int, list<array{line: ArchiveLine, start: DateTimeImmutable}>> $byYear */
        $byYear = [];

        foreach ($past as $item) {
            $start = $item['occurrence']->startDate;
            assert($start !== null);
            $byYear[(int) $start->format('Y')][] = [
                'line' => $this->rows->archiveLine($item['occurrence'], $item['id'], EventsScope::everywhere(), $locale, RowContext::OrganizationPage),
                'start' => $start,
            ];
        }

        krsort($byYear);
        $years = [];

        foreach ($byYear as $year => $lines) {
            usort($lines, static fn (array $a, array $b): int => $b['start'] <=> $a['start']
                ?: strcmp(SearchText::fold($a['line']->title), SearchText::fold($b['line']->title)));

            $years[] = new ArchiveYear($year, array_map(static fn (array $line): ArchiveLine => $line['line'], $lines));
        }

        return $years;
    }

    /**
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    private static function withStatus(array $items, EventOccurrenceStatus ...$statuses): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => in_array($item['status'], $statuses, true)));
    }

    /**
     * @param Item $a
     * @param Item $b
     */
    private static function compareByTitle(array $a, array $b): int
    {
        return strcmp(
            SearchText::fold(EventRowFactory::titleOf($a['occurrence'], RowContext::OrganizationPage)),
            SearchText::fold(EventRowFactory::titleOf($b['occurrence'], RowContext::OrganizationPage)),
        );
    }
}
