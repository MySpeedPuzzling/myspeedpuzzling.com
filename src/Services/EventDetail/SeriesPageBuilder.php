<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\EventDetail\JsonLdSubEvent;
use SpeedPuzzling\Web\Results\EventDetail\SeriesFacts;
use SpeedPuzzling\Web\Results\EventDetail\SeriesFilter;
use SpeedPuzzling\Web\Results\EventDetail\SeriesNextCard;
use SpeedPuzzling\Web\Results\EventDetail\SeriesPage;
use SpeedPuzzling\Web\Results\EventDetail\SeriesPastMonth;
use SpeedPuzzling\Web\Results\EventDetail\SeriesRowDetails;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventsPage\AgendaMonth;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveYear;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RowContext;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The series page (docs/features/events-page/detail-pages.md "Series page", detail-pages-plan.md 1.3) from the series'
 * occurrences (GetEventOccurrences::forSeries()): one list of sessions - editions, and every session of an edition
 * whose rounds fall on separate days - with the events page's rows (EventRowFactory, RowContext::SeriesPage). Pure
 * apart from EventUrls; each rule has a test in SeriesPageBuilderTest.
 *
 * A series with many sessions (docs/features/events-page/high-frequency-series.md "Series page for 200+ editions" - a
 * weekly online contest has ~200 a year) also gets the filter bar (SeriesFilter: category chips, a search over edition
 * names and revealed round puzzle names, a month jump - all in the browser) and its past years in month sections, the
 * newest month of each year open. Every row and line shows its rounds' categories (when the series has two or more)
 * and its revealed round puzzles (SeriesRowDetails). At most MAX_JSON_LD_SUB_EVENTS sub-events go into the JSON-LD.
 *
 * @phpstan-type SeriesItem array{occurrence: EventOccurrence, status: EventOccurrenceStatus, id: int}
 */
readonly final class SeriesPageBuilder
{
    // This many dated sessions or more: the filter bar and the past years in month sections
    public const int FILTER_FROM_SESSIONS = 13;
    // The EventSeries JSON-LD lists at most this many sessions (P26: 200+ would add ~60 KB to one page)
    public const int MAX_JSON_LD_SUB_EVENTS = 50;

    public function __construct(
        private EventRowFactory $rows,
        private EventUrls $urls,
    ) {
    }

    /**
     * @param list<EventOccurrence> $occurrences ordered by start (undated last), then name
     * @param array<string, int> $goingCounts competition id (lower case) => spots taken
     */
    public function build(
        CompetitionSeriesOverview $series,
        array $occurrences,
        array $goingCounts,
        null|EventsViewerData $viewer,
        DateTimeImmutable $now,
        string $locale,
    ): SeriesPage {
        $day = OccurrenceDates::today($now);
        $scope = EventsScope::everywhere();

        /** @var list<SeriesItem> $items */
        $items = [];

        foreach ($occurrences as $occurrence) {
            // Today in the occurrence's own zone - $day (UTC) only counts the "In 3 days" labels
            $items[] = ['occurrence' => $occurrence, 'status' => $occurrence->status($now), 'id' => count($items)];
        }

        $rowOf = function (array $item) use ($goingCounts, $viewer, $scope, $now, $day, $locale): AgendaRow {
            /** @var SeriesItem $item */
            return $this->row($item, $goingCounts, $viewer, $scope, $now, $day, $locale);
        };

        $live = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === EventOccurrenceStatus::Live));
        $upcoming = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === EventOccurrenceStatus::Upcoming));
        $ongoing = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === EventOccurrenceStatus::Ongoing));
        $dateNotSet = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === EventOccurrenceStatus::DateNotSet));
        $past = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === EventOccurrenceStatus::Past));

        // Next: the first live session, else the first upcoming one - long spans are ongoing, never next
        $coming = [...$live, ...$upcoming];
        $nextItem = $coming[0] ?? null;
        $next = null;

        if ($nextItem !== null) {
            $occurrence = $nextItem['occurrence'];
            $next = new SeriesNextCard(
                row: $rowOf($nextItem),
                competitionId: $occurrence->competitionId,
                isGoing: $viewer?->isGoing($occurrence->competitionId) ?? false,
                registrationManaged: $occurrence->registrationManaged,
                registrationLink: $occurrence->registrationLink,
                isPublic: $occurrence->isPublic,
            );
        }

        usort($ongoing, self::compareByTitle(...));
        usort($dateNotSet, self::compareByTitle(...));

        $followTarget = self::isPublic($series) ? FollowTarget::series($series->id) : null;
        $firstDated = null;

        foreach ($items as $item) {
            $start = $item['occurrence']->startDate;

            if ($start !== null && ($firstDated === null || $start < $firstDated)) {
                $firstDated = $start;
            }
        }

        $editionIds = [];

        foreach ($occurrences as $occurrence) {
            $editionIds[strtolower($occurrence->competitionId)] = true;
        }

        $datedCount = count(array_filter($items, static fn (array $item): bool => $item['occurrence']->startDate !== null));
        $pastYears = $this->pastYears($past, $locale);
        $filter = $datedCount >= self::FILTER_FROM_SESSIONS ? self::filter($coming, $past) : null;

        return new SeriesPage(
            next: $next,
            months: $this->months(array_slice($coming, 1), $rowOf),
            ongoing: array_map($rowOf, $ongoing),
            dateNotSet: array_map($rowOf, $dateNotSet),
            pastYears: $pastYears,
            facts: new SeriesFacts(
                editionCount: count($editionIds),
                since: $firstDated,
                comingCount: count($coming),
                next: $nextItem['occurrence']->startDate ?? null,
                nextIsLive: $nextItem !== null && $nextItem['status'] === EventOccurrenceStatus::Live,
                isOnline: $series->isOnline,
                place: EventRowFactory::place($series->isOnline, $series->location, $series->locationCountryCode, $locale),
                website: $series->link,
            ),
            followTarget: $followTarget,
            following: $followTarget !== null && $viewer !== null && $viewer->follows($followTarget),
            subEvents: $this->subEvents($items),
            hasOccurrences: $occurrences !== [],
            filter: $filter,
            pastMonths: $filter !== null ? self::pastMonths($pastYears) : [],
            offersAddMyTime: self::offersAddMyTime($items),
            rowDetails: self::rowDetails($items),
            categories: self::categories($items),
        );
    }

    /**
     * The competitions whose going counts the page shows: live, upcoming and long spans running now (they take
     * registrations too, like on the events page).
     *
     * @param list<EventOccurrence> $occurrences
     *
     * @return list<string> lower-case competition ids
     */
    public static function comingCompetitionIds(array $occurrences, DateTimeImmutable $now): array
    {
        $ids = [];

        foreach ($occurrences as $occurrence) {
            $status = $occurrence->status($now);

            if ($status->isComing() || $status === EventOccurrenceStatus::Ongoing) {
                $ids[strtolower($occurrence->competitionId)] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<SeriesItem> $items live first, then upcoming, by start
     * @param callable(SeriesItem): AgendaRow $rowOf
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
     * One line per past session (never rolled up - the page is the roll-up), by start year, newest year and line first
     *
     * @param list<SeriesItem> $past
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
                'line' => $this->rows->archiveLine($item['occurrence'], $item['id'], EventsScope::everywhere(), $locale, RowContext::SeriesPage),
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
     * Public dated occurrences, one per session: "{event} · {round}" when the session is named by its round. At most
     * MAX_JSON_LD_SUB_EVENTS (P26): every one not over (live, upcoming, a long span running), then the newest past
     * ones - listed by date.
     *
     * @param list<SeriesItem> $items by start
     *
     * @return list<JsonLdSubEvent>
     */
    private function subEvents(array $items): array
    {
        $coming = [];
        $past = [];

        foreach ($items as $item) {
            $occurrence = $item['occurrence'];
            $path = $this->urls->occurrence($occurrence);

            if ($occurrence->isPublic === false || $occurrence->startDate === null || $path === null) {
                continue;
            }

            $name = $occurrence->reference()->displayName();
            $label = $occurrence->sessionLabel();

            $subEvent = new JsonLdSubEvent(
                name: $label !== null ? $name . ' · ' . $label : $name,
                path: $path,
                startDate: $occurrence->startDate,
                endDate: $occurrence->endDate,
                image: $occurrence->logo,
                isOnline: $occurrence->isOnline,
            );

            if ($item['status'] === EventOccurrenceStatus::Past) {
                $past[] = $subEvent;
            } else {
                $coming[] = $subEvent;
            }
        }

        $kept = array_slice($coming, 0, self::MAX_JSON_LD_SUB_EVENTS);
        $kept = [...array_slice(array_reverse($past), 0, self::MAX_JSON_LD_SUB_EVENTS - count($kept)), ...$kept];
        usort($kept, static fn (JsonLdSubEvent $a, JsonLdSubEvent $b): int => $a->startDate <=> $b->startDate);

        return $kept;
    }

    /**
     * "Add my time" in the header (P27): unless every dated public edition is still to come - a series without editions
     * or with undated ones only offers it too, a time of it is first class without an edition (H13)
     *
     * @param list<SeriesItem> $items
     */
    private static function offersAddMyTime(array $items): bool
    {
        $dated = array_filter($items, static fn (array $item): bool => $item['occurrence']->isPublic && $item['occurrence']->startDate !== null);

        return $dated === [] || array_any($dated, static fn (array $item): bool => self::hasStarted($item));
    }

    /**
     * A dated occurrence that has started: live, past, or a long span running now
     *
     * @param SeriesItem $item
     */
    private static function hasStarted(array $item): bool
    {
        return in_array($item['status'], [EventOccurrenceStatus::Live, EventOccurrenceStatus::Past, EventOccurrenceStatus::Ongoing], true);
    }

    /**
     * The filter bar: the months to jump to (its chips are the categories that occur)
     *
     * @param list<SeriesItem> $coming live and upcoming, by start - the first is the Next card
     * @param list<SeriesItem> $past by start
     */
    private static function filter(array $coming, array $past): SeriesFilter
    {
        return new SeriesFilter(
            // The months of the upcoming list - the Next card above it is not repeated there
            upcomingMonths: self::monthStarts(array_slice($coming, 1)),
            pastMonths: array_reverse(self::monthStarts($past)),
        );
    }

    /**
     * What every row and line shows and carries: its rounds' categories, its revealed round puzzles, the filter's
     * search text and month
     *
     * @param list<SeriesItem> $items
     *
     * @return array<int, SeriesRowDetails>
     */
    private static function rowDetails(array $items): array
    {
        $details = [];

        foreach ($items as $item) {
            $occurrence = $item['occurrence'];

            $details[$item['id']] = new SeriesRowDetails(
                categories: $occurrence->roundCategories(),
                puzzleNames: $occurrence->puzzleNames(),
                search: self::searchText([$occurrence->name, $occurrence->editionName(), $occurrence->sessionLabel(), ...$occurrence->puzzleNames()]),
                month: $occurrence->startDate?->format('Y-m') ?? '',
            );
        }

        return $details;
    }

    /**
     * The categories that occur in the sessions' rounds, in the enum's order
     *
     * @param list<SeriesItem> $items
     *
     * @return list<string>
     */
    private static function categories(array $items): array
    {
        $present = [];

        foreach ($items as $item) {
            $present = [...$present, ...$item['occurrence']->roundCategories()];
        }

        return array_values(array_filter(
            array_map(static fn (RoundCategory $category): string => $category->value, RoundCategory::cases()),
            static fn (string $category): bool => in_array($category, $present, true),
        ));
    }

    /**
     * The first days of the months the items start in, each once, in the items' order
     *
     * @param list<SeriesItem> $items
     *
     * @return list<DateTimeImmutable>
     */
    private static function monthStarts(array $items): array
    {
        $months = [];

        foreach ($items as $item) {
            $start = $item['occurrence']->startDate;

            if ($start !== null) {
                $months[$start->format('Y-m')] ??= $start->modify('first day of this month');
            }
        }

        return array_values($months);
    }

    /**
     * Each past year's lines by month, newest first; the newest month of each year open
     *
     * @param list<ArchiveYear> $pastYears
     *
     * @return array<int, list<SeriesPastMonth>>
     */
    private static function pastMonths(array $pastYears): array
    {
        $months = [];

        foreach ($pastYears as $year) {
            /** @var array<string, list<ArchiveLine>> $byMonth */
            $byMonth = [];
            $firstDays = [];

            foreach ($year->lines as $line) {
                $key = $line->from->format('Y-m');
                $byMonth[$key][] = $line;
                $firstDays[$key] ??= $line->from->modify('first day of this month');
            }

            $sections = [];

            foreach ($byMonth as $key => $lines) {
                $sections[] = new SeriesPastMonth($firstDays[$key], $lines, $sections === []);
            }

            $months[$year->year] = $sections;
        }

        return $months;
    }

    /**
     * @param list<null|string> $parts
     */
    private static function searchText(array $parts): string
    {
        $texts = [];

        foreach ($parts as $part) {
            $folded = $part !== null ? SearchText::fold($part) : '';

            if ($folded !== '' && in_array($folded, $texts, true) === false) {
                $texts[] = $folded;
            }
        }

        return implode(' ', $texts);
    }

    /**
     * @param SeriesItem $item
     * @param array<string, int> $goingCounts
     */
    private function row(
        array $item,
        array $goingCounts,
        null|EventsViewerData $viewer,
        EventsScope $scope,
        DateTimeImmutable $now,
        DateTimeImmutable $day,
        string $locale,
    ): AgendaRow {
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
            RowContext::SeriesPage,
        );
    }

    /**
     * @param SeriesItem $a
     * @param SeriesItem $b
     */
    private static function compareByTitle(array $a, array $b): int
    {
        return strcmp(
            SearchText::fold(EventRowFactory::titleOf($a['occurrence'], RowContext::SeriesPage)),
            SearchText::fold(EventRowFactory::titleOf($b['occurrence'], RowContext::SeriesPage)),
        );
    }

    private static function isPublic(CompetitionSeriesOverview $series): bool
    {
        return $series->isPubliclyVisible();
    }
}
