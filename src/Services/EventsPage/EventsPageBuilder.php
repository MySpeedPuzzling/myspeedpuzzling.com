<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\AgendaMonth;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveYear;
use SpeedPuzzling\Web\Results\EventsPage\CountryCount;
use SpeedPuzzling\Web\Results\EventsPage\CountryRegionGroup;
use SpeedPuzzling\Web\Results\EventsPage\DateLeaf;
use SpeedPuzzling\Web\Results\EventsPage\EventsArchivePage;
use SpeedPuzzling\Web\Results\EventsPage\EventsPage;
use SpeedPuzzling\Web\Results\EventsPage\EventsSummary;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\RowTag;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\SeriesLine;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Results\EventsPage\SessionChip;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Results\EventsPage\YourEvent;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryRegion;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * Turns the occurrences and series into everything the events page renders (docs/features/events-page/
 * implementation-plan.md, 1.5 - each rule has a test in EventsPageBuilderTest). Pure apart from EventUrls. One row and
 * one archive line are EventRowFactory's (shared with the series page).
 *
 * Every row of every scope is built - `visible` tells the request's scope; the browser switches scopes and searches
 * over the index. Only public items are counted; ones waiting for approval (admins only) are rows with a tag, never
 * counted, never in "Your events" nor in the archive.
 *
 * @phpstan-type ListedOccurrence array{occurrence: EventOccurrence, status: EventOccurrenceStatus, id: int}
 */
readonly final class EventsPageBuilder
{
    public const int LONG_RUN_DAYS = 14;
    public const int CHIP_COUNTRIES = 6;
    public const int ARCHIVE_PREVIEW_LINES = 5;
    // "In 12 days" in coral
    public const int SOON_DAYS = 14;
    // no "when" label beyond
    public const int RELATIVE_DAYS = 30;

    private EventRowFactory $rows;

    public function __construct(
        private EventUrls $urls,
        private EventsIndexFactory $indexFactory,
        // the row rules (shared with the series page); built from the URLs when not given - the unit tests' way
        null|EventRowFactory $rows = null,
    ) {
        $this->rows = $rows ?? new EventRowFactory($urls);
    }

    /**
     * @param list<EventOccurrence> $occurrences ordered by start (undated last), then name - GetEventOccurrences::all()
     * @param list<EventSeriesRow> $series
     * @param array<string, int> $goingCounts competition id => spots taken
     * @param DateTimeImmutable $today the request's "now": its UTC date is today, the instant decides registration windows
     */
    public function build(
        array $occurrences,
        array $series,
        array $goingCounts,
        null|EventsViewerData $viewer,
        EventsScope $scope,
        DateTimeImmutable $today,
        string $locale,
        null|CountryCode $homeCountry,
    ): EventsPage {
        $now = $today;
        $day = OccurrenceDates::today($today);

        // Every occurrence shown on some view gets an index id (its position); "date not set" editions only count in
        // their series, a past one waiting for approval is nowhere
        /** @var list<ListedOccurrence> $listed */
        $listed = [];
        /** @var array<string, list<array{occurrence: EventOccurrence, status: EventOccurrenceStatus}>> $editionsBySeries */
        $editionsBySeries = [];

        foreach ($occurrences as $occurrence) {
            // Today in the occurrence's own zone - $day (UTC) only counts the "In 3 days" labels
            $status = $occurrence->status($now);

            if ($occurrence->seriesId !== null) {
                $editionsBySeries[$occurrence->seriesId][] = ['occurrence' => $occurrence, 'status' => $status];
            }

            if ($status === EventOccurrenceStatus::DateNotSet) {
                continue;
            }

            if ($occurrence->isPublic === false && $status === EventOccurrenceStatus::Past) {
                continue;
            }

            $listed[] = ['occurrence' => $occurrence, 'status' => $status, 'id' => count($listed)];
        }

        // Series lines - their index ids follow the occurrences'
        $seriesLines = $this->seriesLines($series, $editionsBySeries, $viewer, $scope, $locale, count($listed));
        $seriesIndexIds = [];
        $seriesRows = [];

        foreach ($seriesLines as $line) {
            $seriesIndexIds[$line->seriesId] = $line->indexId;
        }

        foreach ($series as $seriesRow) {
            $seriesRows[$seriesRow->id] = $seriesRow;
        }

        // Index
        $index = [];

        foreach ($listed as $item) {
            $occurrence = $item['occurrence'];
            $index[] = $this->indexFactory->occurrence(
                $item['id'],
                $occurrence,
                $item['status'],
                $this->urls->occurrence($occurrence),
                self::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
                $occurrence->seriesId !== null ? ($seriesIndexIds[$occurrence->seriesId] ?? null) : null,
                $locale,
            );
        }

        $sortedLines = $seriesLines;
        usort($sortedLines, static fn (SeriesLine $a, SeriesLine $b): int => $a->indexId <=> $b->indexId);

        foreach ($sortedLines as $line) {
            $index[] = $this->indexFactory->series($line, $seriesRows[$line->seriesId], $locale);
        }

        // Agenda
        $live = [];
        $upcoming = [];
        $tba = [];
        $ongoing = [];
        $past = [];

        foreach ($listed as $item) {
            match ($item['status']) {
                EventOccurrenceStatus::Live => $live[] = $item,
                EventOccurrenceStatus::Upcoming => $upcoming[] = $item,
                EventOccurrenceStatus::Tba => $tba[] = $item,
                EventOccurrenceStatus::Ongoing => $ongoing[] = $item,
                EventOccurrenceStatus::Past => $past[] = $item,
                EventOccurrenceStatus::DateNotSet => null,
            };
        }

        usort($tba, self::compareByTitle(...));
        usort($ongoing, self::compareByTitle(...));

        $rowOf = function (array $item, null|string $logo = null) use ($goingCounts, $viewer, $scope, $now, $day, $locale): AgendaRow {
            /** @var ListedOccurrence $item */
            return $this->rows->row($item['occurrence'], $item['status'], $item['id'], $goingCounts, $viewer, $scope, $now, $day, $locale, $logo);
        };

        $publicPast = array_values(array_filter($past, static fn (array $item): bool => $item['occurrence']->isPublic));
        $archiveYears = $this->archiveYears($publicPast, $scope, $locale);

        // Counts (public only): live, upcoming and long spans running now (ongoing with dates) - a scope with one of
        // those is never "nothing planned"
        $publicComing = array_values(array_filter(
            $listed,
            static fn (array $item): bool => $item['occurrence']->isPublic && self::isOn($item['occurrence'], $item['status']),
        ));

        $everywhereUpcoming = count($publicComing);
        $onlineUpcoming = count(array_filter($publicComing, static fn (array $item): bool => $item['occurrence']->isOnline));
        $scopeUpcoming = count(array_filter(
            $publicComing,
            static fn (array $item): bool => $scope->matches($item['occurrence']->isOnline, $item['occurrence']->countryCode),
        ));
        $comingCountries = [];

        foreach ($publicComing as $item) {
            $country = $item['occurrence']->countryCode;

            if ($item['occurrence']->isOnline === false && $country !== null) {
                $comingCountries[$country->name] = true;
            }
        }

        $countryCounts = $this->countryCounts($listed, $locale);
        $chipCountries = $this->chipCountries($countryCounts, $scope, $locale);

        $scopeLast = null;

        foreach ($archiveYears as $archiveYear) {
            foreach ($archiveYear->lines as $line) {
                if ($line->visible) {
                    $scopeLast = $line;

                    break 2;
                }
            }
        }

        if ($scopeLast !== null && $scopeLast->isRollUp()) {
            // The empty state names one event: the newest of the roll-up
            $scopeLast = $this->singleArchiveLine($this->newestOf($publicPast, $scopeLast->indexIds), $scope, $locale);
        }

        return new EventsPage(
            summary: new EventsSummary(
                upcomingDates: $everywhereUpcoming,
                countries: count($comingCountries),
                hasOnline: $onlineUpcoming > 0,
                series: count(array_filter($series, static fn (EventSeriesRow $row): bool => $row->isPublic)),
            ),
            yourEvents: $this->yourEvents($listed, $viewer, $rowOf),
            live: array_map(static fn (array $item): AgendaRow => $rowOf($item), $live),
            months: $this->months($upcoming, $rowOf, $viewer, $scope, $day, $locale),
            tba: array_map(static fn (array $item): AgendaRow => $rowOf($item), $tba),
            seriesInPerson: array_values(array_filter($seriesLines, static fn (SeriesLine $line): bool => $line->isOnline === false)),
            seriesOnline: array_values(array_filter($seriesLines, static fn (SeriesLine $line): bool => $line->isOnline)),
            ongoing: array_map(static fn (array $item): AgendaRow => $rowOf($item), $ongoing),
            archiveYears: $archiveYears,
            countryCounts: $countryCounts,
            chipCountries: $chipCountries,
            homeCountry: $homeCountry !== null ? $this->countryCountOf($homeCountry, $countryCounts, $locale) : null,
            onlineUpcoming: $onlineUpcoming,
            everywhereUpcoming: $everywhereUpcoming,
            organizedCount: $viewer?->organizedCount() ?? 0,
            scope: $scope,
            scopeUpcoming: $scopeUpcoming,
            scopeLast: $scopeLast,
            index: $index,
            itemListUrls: $this->itemListUrls([...$live, ...$upcoming, ...$tba]),
            regions: $this->regions($countryCounts),
        );
    }

    /**
     * One year of the archive (`events_archive`), public past occurrences only. Null when the year has none - the
     * page answers 404.
     *
     * @param list<EventOccurrence> $occurrences
     */
    public function buildArchive(array $occurrences, int $year, DateTimeImmutable $today, string $locale): null|EventsArchivePage
    {
        $past = [];
        $id = 0;

        foreach ($occurrences as $occurrence) {
            if ($occurrence->isPublic === false || $occurrence->status($today) !== EventOccurrenceStatus::Past) {
                continue;
            }

            $past[] = ['occurrence' => $occurrence, 'status' => EventOccurrenceStatus::Past, 'id' => $id++];
        }

        $years = $this->archiveYears($past, EventsScope::everywhere(), $locale);
        $selected = null;

        foreach ($years as $archiveYear) {
            if ($archiveYear->year === $year) {
                $selected = $archiveYear;
            }
        }

        if ($selected === null) {
            return null;
        }

        $urls = [];

        foreach ($past as $item) {
            if ((int) $item['occurrence']->startDate?->format('Y') !== $year) {
                continue;
            }

            $url = $this->urls->occurrencePage($item['occurrence']);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return new EventsArchivePage(
            year: $year,
            lines: $selected->lines,
            years: array_map(static fn (ArchiveYear $archiveYear): int => $archiveYear->year, $years),
            itemListUrls: array_values(array_unique(array_reverse($urls))),
            count: $selected->occurrenceCount(),
        );
    }

    /**
     * Upcoming rows by month; several upcoming editions of one series in one month are one row at the first one's
     * position.
     *
     * @param list<ListedOccurrence> $upcoming
     * @param callable(ListedOccurrence): AgendaRow $rowOf
     *
     * @return list<AgendaMonth>
     */
    private function months(array $upcoming, callable $rowOf, null|EventsViewerData $viewer, EventsScope $scope, DateTimeImmutable $day, string $locale): array
    {
        /** @var array<string, list<ListedOccurrence>> $byMonth */
        $byMonth = [];

        foreach ($upcoming as $item) {
            $start = $item['occurrence']->startDate;
            assert($start !== null);
            $byMonth[$start->format('Y-m')][] = $item;
        }

        $months = [];

        foreach ($byMonth as $items) {
            $editionsBySeries = [];

            foreach ($items as $item) {
                if ($item['occurrence']->seriesId !== null) {
                    $editionsBySeries[$item['occurrence']->seriesId][] = $item;
                }
            }

            $rows = [];
            $grouped = [];

            foreach ($items as $item) {
                $seriesId = $item['occurrence']->seriesId;

                if ($seriesId !== null && count($editionsBySeries[$seriesId]) >= 2) {
                    if (isset($grouped[$seriesId]) === false) {
                        $grouped[$seriesId] = true;
                        $rows[] = $this->groupRow($editionsBySeries[$seriesId], $viewer, $scope, $day, $locale);
                    }

                    continue;
                }

                $rows[] = $rowOf($item);
            }

            $first = $items[0]['occurrence']->startDate;
            assert($first !== null);
            $firstDay = $first->modify('first day of this month');
            $visibleCount = 0;

            foreach ($rows as $row) {
                if ($row->visible && $row->isPending === false) {
                    $visibleCount += $row->datesCount();
                }
            }

            $months[] = new AgendaMonth(
                year: (int) $firstDay->format('Y'),
                month: (int) $firstDay->format('n'),
                firstDay: $firstDay,
                rows: $rows,
                visibleCount: $visibleCount,
            );
        }

        return $months;
    }

    /**
     * @param non-empty-list<ListedOccurrence> $items
     */
    private function groupRow(array $items, null|EventsViewerData $viewer, EventsScope $scope, DateTimeImmutable $day, string $locale): AgendaRow
    {
        $first = $items[0]['occurrence'];
        $last = $items[count($items) - 1]['occurrence'];
        $seriesId = (string) $first->seriesId;
        $seriesName = (string) $first->seriesName;
        $followTarget = FollowTarget::series($seriesId);
        $going = false;
        $sessions = [];

        foreach ($items as $item) {
            $occurrence = $item['occurrence'];
            $going = $going || ($viewer?->isGoing($occurrence->competitionId) ?? false);
            assert($occurrence->startDate !== null);

            $sessions[] = new SessionChip(
                indexId: $item['id'],
                url: $this->urls->occurrence($occurrence),
                date: $occurrence->startDate,
                title: $occurrence->subtitle() ?? $seriesName,
            );
        }

        $tags = [];

        if ($first->isPublic === false) {
            $tags[] = new RowTag(RowTagType::WaitingForApproval);
        }

        if ($going) {
            $tags[] = new RowTag(RowTagType::Going);
        }

        $tags[] = new RowTag(RowTagType::Recurring);

        return new AgendaRow(
            indexIds: array_map(static fn (array $item): int => $item['id'], $items),
            isGroup: true,
            title: $seriesName,
            editionName: null,
            url: $this->urls->series($first->seriesSlug),
            leaf: new DateLeaf($first->startDate, null, self::tone($first->isOnline, EventOccurrenceStatus::Upcoming)),
            place: self::place($first->isOnline, $first->location, $first->countryCode, $locale),
            tags: $tags,
            when: self::when(EventOccurrenceStatus::Upcoming, $first->startDate, $day),
            sessions: $sessions,
            status: EventOccurrenceStatus::Upcoming,
            scopeKey: EventsScope::keyOf($first->isOnline, $first->countryCode),
            from: $first->startDate?->format('Y-m-d'),
            to: $last->startDate?->format('Y-m-d'),
            followTarget: $followTarget,
            followName: $seriesName,
            following: $viewer !== null && $viewer->follows($followTarget),
            manage: new ManageRef(ManageRef::KIND_SERIES, $seriesId, $seriesName),
            isPending: $first->isPublic === false,
            visible: $scope->matches($first->isOnline, $first->countryCode),
        );
    }

    /**
     * @param list<EventSeriesRow> $series
     * @param array<string, list<array{occurrence: EventOccurrence, status: EventOccurrenceStatus}>> $editionsBySeries
     *
     * @return list<SeriesLine> sorted: next (or live) by date, then last (newest first), then none, then name
     */
    private function seriesLines(array $series, array $editionsBySeries, null|EventsViewerData $viewer, EventsScope $scope, string $locale, int $firstIndexId): array
    {
        $lines = [];

        foreach ($series as $row) {
            $editions = $editionsBySeries[$row->id] ?? [];
            $next = null;
            $live = null;
            $ongoing = null;
            $last = null;
            // Sessions of one edition are one edition
            $editionIds = [];

            foreach ($editions as $edition) {
                $start = $edition['occurrence']->startDate;
                $editionIds[$edition['occurrence']->competitionId] = true;

                match ($edition['status']) {
                    EventOccurrenceStatus::Upcoming => $next = $next === null || $start < $next ? $start : $next,
                    EventOccurrenceStatus::Live => $live = $live === null || $start < $live ? $start : $live,
                    EventOccurrenceStatus::Ongoing => $ongoing = $ongoing === null || $start < $ongoing ? $start : $ongoing,
                    EventOccurrenceStatus::Past => $last = $last === null || $start > $last ? $start : $last,
                    default => null,
                };
            }

            $seriesNext = match (true) {
                $next !== null => new SeriesNext(SeriesNext::NEXT, $next),
                $live !== null => new SeriesNext(SeriesNext::LIVE, $live),
                $ongoing !== null => new SeriesNext(SeriesNext::ONGOING, $ongoing),
                $last !== null => new SeriesNext(SeriesNext::LAST, $last),
                default => new SeriesNext(SeriesNext::NONE, null),
            };

            $followTarget = FollowTarget::series($row->id);

            $lines[] = new SeriesLine(
                indexId: 0,
                seriesId: $row->id,
                name: $row->name,
                url: $this->urls->series($row->slug),
                place: self::place($row->isOnline, $row->location, $row->countryCode, $locale),
                isOnline: $row->isOnline,
                editionCount: count($editionIds),
                next: $seriesNext,
                followTarget: $followTarget,
                following: $viewer !== null && $viewer->follows($followTarget),
                manage: new ManageRef(ManageRef::KIND_SERIES, $row->id, $row->name),
                isPending: $row->isPublic === false,
                scopeKey: EventsScope::keyOf($row->isOnline, $row->countryCode),
                visible: $scope->matches($row->isOnline, $row->countryCode),
                // "by …" under the line - only a publicly visible organization (docs/features/organizations/README.md)
                organization: $row->organization?->isPublic === true ? $row->organization : null,
            );
        }

        $rank = static fn (SeriesLine $line): int => match ($line->next->type) {
            SeriesNext::LIVE, SeriesNext::NEXT, SeriesNext::ONGOING => 0,
            SeriesNext::LAST => 1,
            default => 2,
        };

        usort($lines, static function (SeriesLine $a, SeriesLine $b) use ($rank): int {
            $byRank = $rank($a) <=> $rank($b);

            if ($byRank !== 0) {
                return $byRank;
            }

            if ($a->next->date !== null && $b->next->date !== null && $a->next->date != $b->next->date) {
                return $rank($a) === 1 ? $b->next->date <=> $a->next->date : $a->next->date <=> $b->next->date;
            }

            return strcmp(SearchText::fold($a->name), SearchText::fold($b->name));
        });

        $indexed = [];

        foreach ($lines as $position => $line) {
            $indexed[] = new SeriesLine(
                indexId: $firstIndexId + $position,
                seriesId: $line->seriesId,
                name: $line->name,
                url: $line->url,
                place: $line->place,
                isOnline: $line->isOnline,
                editionCount: $line->editionCount,
                next: $line->next,
                followTarget: $line->followTarget,
                following: $line->following,
                manage: $line->manage,
                isPending: $line->isPending,
                scopeKey: $line->scopeKey,
                visible: $line->visible,
                organization: $line->organization,
            );
        }

        return $indexed;
    }

    /**
     * Past public occurrences by start year, newest year first; within a year several editions of one series are one
     * line, placed at its newest edition; lines newest first.
     *
     * @param list<ListedOccurrence> $past
     *
     * @return list<ArchiveYear>
     */
    private function archiveYears(array $past, EventsScope $scope, string $locale): array
    {
        /** @var array<int, list<ListedOccurrence>> $byYear */
        $byYear = [];

        foreach ($past as $item) {
            $start = $item['occurrence']->startDate;
            assert($start !== null);
            $byYear[(int) $start->format('Y')][] = $item;
        }

        krsort($byYear);
        $years = [];

        foreach ($byYear as $year => $items) {
            // A series' editions, or the sessions of one one-time event, are one line
            $grouped = [];

            foreach ($items as $item) {
                $grouped[self::archiveKey($item['occurrence'])][] = $item;
            }

            /** @var list<array{line: ArchiveLine, sort: DateTimeImmutable}> $lines */
            $lines = [];
            $rolledUp = [];

            foreach ($items as $item) {
                $occurrence = $item['occurrence'];
                $key = self::archiveKey($occurrence);

                if (count($grouped[$key]) >= 2) {
                    if (isset($rolledUp[$key]) === false) {
                        $rolledUp[$key] = true;
                        $lines[] = $this->rollUpLine($grouped[$key], $year, $scope, $locale);
                    }

                    continue;
                }

                $start = $occurrence->startDate;
                assert($start !== null);
                $lines[] = ['line' => $this->singleArchiveLine($item, $scope, $locale), 'sort' => $start];
            }

            usort($lines, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']
                ?: strcmp(SearchText::fold($a['line']->title), SearchText::fold($b['line']->title)));

            $years[] = new ArchiveYear($year, array_map(static fn (array $line): ArchiveLine => $line['line'], $lines));
        }

        return $years;
    }

    private static function archiveKey(EventOccurrence $occurrence): string
    {
        return $occurrence->seriesId !== null ? 's:' . $occurrence->seriesId : 'c:' . $occurrence->competitionId;
    }

    /**
     * @param ListedOccurrence $item
     */
    private function singleArchiveLine(array $item, EventsScope $scope, string $locale): ArchiveLine
    {
        return $this->rows->archiveLine($item['occurrence'], $item['id'], $scope, $locale);
    }

    /**
     * Several editions of a series in one year: "Harbor Jigsaw Nights · 5 editions in 2026", counting editions, not
     * sessions. The sessions of a single edition (or one-time event) are its own line instead, from its first to its
     * last day of the year, linking its page.
     *
     * @param non-empty-list<ListedOccurrence> $items
     *
     * @return array{line: ArchiveLine, sort: DateTimeImmutable}
     */
    private function rollUpLine(array $items, int $year, EventsScope $scope, string $locale): array
    {
        $first = null;
        $newest = null;
        $hasResults = false;
        $competitions = [];

        foreach ($items as $item) {
            $start = $item['occurrence']->startDate;
            assert($start !== null);

            if ($first === null || $start < $first) {
                $first = $start;
            }

            if ($newest === null || $start >= ($newest['occurrence']->startDate ?? $start)) {
                $newest = $item;
            }

            $hasResults = $hasResults || $item['occurrence']->hasResults;
            $competitions[$item['occurrence']->competitionId] = true;
        }

        $occurrence = $newest['occurrence'];
        $last = $occurrence->startDate;
        assert($last !== null);
        $ids = array_map(static fn (array $item): int => $item['id'], $items);
        sort($ids);
        $editionCount = count($competitions);

        if ($editionCount === 1) {
            $to = $occurrence->endDate ?? $last;

            return [
                'line' => new ArchiveLine(
                    indexIds: $ids,
                    title: self::titleOf($occurrence),
                    url: $this->urls->occurrencePage($occurrence),
                    from: $first,
                    to: $to > $first ? $to : null,
                    editionCount: 1,
                    monthFrom: (int) $first->format('n'),
                    monthTo: (int) $to->format('n'),
                    hasResults: $hasResults,
                    place: self::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
                    scopeKey: EventsScope::keyOf($occurrence->isOnline, $occurrence->countryCode),
                    visible: $scope->matches($occurrence->isOnline, $occurrence->countryCode),
                    editionName: $occurrence->editionName(),
                    year: $year,
                ),
                'sort' => $last,
            ];
        }

        return [
            'line' => new ArchiveLine(
                indexIds: $ids,
                title: (string) $occurrence->seriesName,
                url: $this->urls->series($occurrence->seriesSlug),
                from: $first,
                to: $last,
                editionCount: $editionCount,
                monthFrom: (int) $first->format('n'),
                monthTo: (int) $last->format('n'),
                hasResults: $hasResults,
                place: self::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
                scopeKey: EventsScope::keyOf($occurrence->isOnline, $occurrence->countryCode),
                visible: $scope->matches($occurrence->isOnline, $occurrence->countryCode),
                year: $year,
            ),
            'sort' => $last,
        ];
    }

    /**
     * @param list<ListedOccurrence> $past
     * @param list<int> $ids
     *
     * @return ListedOccurrence
     */
    private function newestOf(array $past, array $ids): array
    {
        $newest = null;

        foreach ($past as $item) {
            if (in_array($item['id'], $ids, true) && ($newest === null || $item['occurrence']->startDate >= $newest['occurrence']->startDate)) {
                $newest = $item;
            }
        }

        assert($newest !== null);

        return $newest;
    }

    /**
     * Going (live, upcoming, TBA, ongoing), followed one-time events (also ongoing ones) and the next live-or-upcoming
     * edition of every followed series - public only, one row per competition (Going wins; of a competition with
     * several sessions only the next one not over), by start, undated last. A followed organization (publicly visible,
     * docs/features/organizations/README.md "Follow") counts as following each of its one-time events and each of its
     * series: the same rows, so an event or series followed both ways is listed once.
     *
     * @param list<ListedOccurrence> $listed in date order
     * @param callable(ListedOccurrence, null|string): AgendaRow $rowOf
     *
     * @return list<YourEvent>
     */
    private function yourEvents(array $listed, null|EventsViewerData $viewer, callable $rowOf): array
    {
        if ($viewer === null) {
            return [];
        }

        /** @var array<string, array{item: ListedOccurrence, mark: 'going'|'following'}> $picked */
        $picked = [];
        /** @var array<string, ListedOccurrence> $nextOfSeries */
        $nextOfSeries = [];

        foreach ($listed as $item) {
            $occurrence = $item['occurrence'];
            $status = $item['status'];
            $notOver = $status->isComing() || $status === EventOccurrenceStatus::Ongoing;

            // The first session not over stands for its competition
            if ($occurrence->isPublic === false || isset($picked[$occurrence->competitionId])) {
                continue;
            }

            if ($notOver && $viewer->isGoing($occurrence->competitionId)) {
                $picked[$occurrence->competitionId] = ['item' => $item, 'mark' => YourEvent::MARK_GOING];

                continue;
            }

            // Through its organization: only while the organization itself is public (a draft one hides only itself)
            $followedOrganization = $occurrence->organization !== null
                && $occurrence->organization->isPublic
                && $viewer->followsOrganization($occurrence->organization->id);

            if (
                $occurrence->isEdition() === false
                && $notOver
                && ($followedOrganization || $viewer->follows(FollowTarget::competition($occurrence->competitionId)))
            ) {
                $picked[$occurrence->competitionId] = ['item' => $item, 'mark' => YourEvent::MARK_FOLLOWING];

                continue;
            }

            $seriesId = $occurrence->seriesId;

            if (
                $seriesId !== null
                && $notOver
                && ($followedOrganization || $viewer->follows(FollowTarget::series($seriesId)))
                && (isset($nextOfSeries[$seriesId]) === false || $occurrence->startDate < $nextOfSeries[$seriesId]['occurrence']->startDate)
            ) {
                $nextOfSeries[$seriesId] = $item;
            }
        }

        foreach ($nextOfSeries as $item) {
            $picked[$item['occurrence']->competitionId] ??= ['item' => $item, 'mark' => YourEvent::MARK_FOLLOWING];
        }

        $picked = array_values($picked);

        usort($picked, static function (array $a, array $b): int {
            $aStart = $a['item']['occurrence']->startDate;
            $bStart = $b['item']['occurrence']->startDate;

            if ($aStart === null || $bStart === null) {
                return ($aStart === null) <=> ($bStart === null)
                    ?: strcmp(SearchText::fold(self::titleOf($a['item']['occurrence'])), SearchText::fold(self::titleOf($b['item']['occurrence'])));
            }

            return $aStart <=> $bStart ?: $a['item']['id'] <=> $b['item']['id'];
        });

        return array_map(
            static fn (array $pick): YourEvent => new YourEvent($rowOf($pick['item'], $pick['item']['occurrence']->logo), $pick['mark']),
            $picked,
        );
    }

    /**
     * Every country with an in-person public occurrence: upcoming (live, upcoming, a long span running), TBA, past - by
     * upcoming, then name.
     *
     * @param list<ListedOccurrence> $listed
     *
     * @return list<CountryCount>
     */
    private function countryCounts(array $listed, string $locale): array
    {
        /** @var array<string, array{code: CountryCode, upcoming: int, tba: int, past: int}> $counts */
        $counts = [];

        foreach ($listed as $item) {
            $occurrence = $item['occurrence'];
            $country = $occurrence->countryCode;

            if ($occurrence->isPublic === false || $occurrence->isOnline || $country === null) {
                continue;
            }

            $counts[$country->name] ??= ['code' => $country, 'upcoming' => 0, 'tba' => 0, 'past' => 0];

            if (self::isOn($occurrence, $item['status'])) {
                $counts[$country->name]['upcoming']++;

                continue;
            }

            match ($item['status']) {
                EventOccurrenceStatus::Tba => $counts[$country->name]['tba']++,
                EventOccurrenceStatus::Past => $counts[$country->name]['past']++,
                default => null,
            };
        }

        $countryCounts = array_map(
            static fn (array $count): CountryCount => new CountryCount(
                code: $count['code'],
                name: $count['code']->localizedName($locale),
                upcoming: $count['upcoming'],
                tba: $count['tba'],
                past: $count['past'],
                region: CountryRegion::forCountry($count['code']),
            ),
            array_values($counts),
        );

        usort($countryCounts, self::byUpcomingThenName(...));

        return $countryCounts;
    }

    /**
     * @param list<CountryCount> $countryCounts
     *
     * @return list<CountryCount>
     */
    private function chipCountries(array $countryCounts, EventsScope $scope, string $locale): array
    {
        $chips = array_slice(
            array_values(array_filter($countryCounts, static fn (CountryCount $count): bool => $count->upcoming > 0)),
            0,
            self::CHIP_COUNTRIES,
        );

        $active = $scope->countryCode();

        if ($active !== null) {
            foreach ($chips as $chip) {
                if ($chip->code === $active) {
                    return $chips;
                }
            }

            $chips[] = $this->countryCountOf($active, $countryCounts, $locale);
        }

        return $chips;
    }

    /**
     * @param list<CountryCount> $countryCounts
     */
    private function countryCountOf(CountryCode $country, array $countryCounts, string $locale): CountryCount
    {
        foreach ($countryCounts as $count) {
            if ($count->code === $country) {
                return $count;
            }
        }

        return new CountryCount($country, $country->localizedName($locale), 0, 0, 0, CountryRegion::forCountry($country));
    }

    /**
     * @param list<CountryCount> $countryCounts sorted by upcoming, then name
     *
     * @return list<CountryRegionGroup>
     */
    private function regions(array $countryCounts): array
    {
        $groups = [];

        foreach (CountryRegion::cases() as $region) {
            $countries = array_values(array_filter($countryCounts, static fn (CountryCount $count): bool => $count->region === $region));

            if ($countries !== []) {
                $groups[] = new CountryRegionGroup($region, $countries);
            }
        }

        return $groups;
    }

    /**
     * @param list<ListedOccurrence> $items in agenda order
     *
     * @return list<string>
     */
    private function itemListUrls(array $items): array
    {
        $urls = [];

        foreach ($items as $item) {
            if ($item['occurrence']->isPublic === false) {
                continue;
            }

            $url = $this->urls->occurrencePage($item['occurrence']);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        // Sessions of one event share its page
        return array_values(array_unique($urls));
    }

    /**
     * @param ListedOccurrence $a
     * @param ListedOccurrence $b
     */
    private static function compareByTitle(array $a, array $b): int
    {
        return strcmp(SearchText::fold(self::titleOf($a['occurrence'])), SearchText::fold(self::titleOf($b['occurrence'])));
    }

    private static function byUpcomingThenName(CountryCount $a, CountryCount $b): int
    {
        return $b->upcoming <=> $a->upcoming ?: strcmp(SearchText::fold($a->name), SearchText::fold($b->name));
    }

    /**
     * Live, upcoming, or a long span running now - what the counts call "upcoming"
     */
    private static function isOn(EventOccurrence $occurrence, EventOccurrenceStatus $status): bool
    {
        return $status === EventOccurrenceStatus::Live
            || $status === EventOccurrenceStatus::Upcoming
            || ($status === EventOccurrenceStatus::Ongoing && $occurrence->startDate !== null);
    }

    private static function titleOf(EventOccurrence $occurrence): string
    {
        return EventRowFactory::titleOf($occurrence);
    }

    /**
     * @return 'in_person'|'online'|'muted'
     */
    private static function tone(bool $isOnline, EventOccurrenceStatus $status): string
    {
        return EventRowFactory::tone($isOnline, $status);
    }

    private static function when(EventOccurrenceStatus $status, null|DateTimeImmutable $start, DateTimeImmutable $day): null|WhenLabel
    {
        return EventRowFactory::when($status, $start, $day);
    }

    /**
     * The city is left out when the location already holds the country's name (localised or English) - then the
     * country shows alone (EventRowFactory::place()).
     */
    public static function place(bool $isOnline, null|string $location, null|CountryCode $country, string $locale): Place
    {
        return EventRowFactory::place($isOnline, $location, $country, $locale);
    }
}
