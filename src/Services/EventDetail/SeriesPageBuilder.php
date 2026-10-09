<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\EventDetail\JsonLdSubEvent;
use SpeedPuzzling\Web\Results\EventDetail\SeriesFacts;
use SpeedPuzzling\Web\Results\EventDetail\SeriesNextCard;
use SpeedPuzzling\Web\Results\EventDetail\SeriesPage;
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
use SpeedPuzzling\Web\Value\RowContext;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The series page (docs/features/events-page/detail-pages.md "Series page", detail-pages-plan.md 1.3) from the series'
 * occurrences (GetEventOccurrences::forSeries()): one list of sessions - editions, and every session of an edition
 * whose rounds fall on separate days - with the events page's rows (EventRowFactory, RowContext::SeriesPage). Pure
 * apart from EventUrls; each rule has a test in SeriesPageBuilderTest.
 *
 * @phpstan-type SeriesItem array{occurrence: EventOccurrence, status: EventOccurrenceStatus, id: int}
 */
readonly final class SeriesPageBuilder
{
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

        return new SeriesPage(
            next: $next,
            months: $this->months(array_slice($coming, 1), $rowOf),
            ongoing: array_map($rowOf, $ongoing),
            dateNotSet: array_map($rowOf, $dateNotSet),
            pastYears: $this->pastYears($past, $locale),
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
            subEvents: $this->subEvents($occurrences),
            hasOccurrences: $occurrences !== [],
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
     * Public dated occurrences, one per session: "{event} · {round}" when the session is named by its round
     *
     * @param list<EventOccurrence> $occurrences
     *
     * @return list<JsonLdSubEvent>
     */
    private function subEvents(array $occurrences): array
    {
        $subEvents = [];

        foreach ($occurrences as $occurrence) {
            $path = $this->urls->occurrence($occurrence);

            if ($occurrence->isPublic === false || $occurrence->startDate === null || $path === null) {
                continue;
            }

            $name = $occurrence->reference()->displayName();
            $label = $occurrence->sessionLabel();

            $subEvents[] = new JsonLdSubEvent(
                name: $label !== null ? $name . ' · ' . $label : $name,
                path: $path,
                startDate: $occurrence->startDate,
                endDate: $occurrence->endDate,
                image: $occurrence->logo,
                isOnline: $occurrence->isOnline,
            );
        }

        return $subEvents;
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
