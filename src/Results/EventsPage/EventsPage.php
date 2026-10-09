<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\EventsScope;

/**
 * Everything the events page renders (docs/features/events-page/implementation-plan.md, 1.5). Every row of every
 * scope is in it - `visible` says whether it is in the request's scope; the browser switches scopes over the index.
 */
readonly final class EventsPage
{
    /**
     * @param list<YourEvent> $yourEvents
     * @param list<AgendaRow> $live
     * @param list<AgendaMonth> $months
     * @param list<AgendaRow> $tba
     * @param list<SeriesLine> $seriesInPerson
     * @param list<SeriesLine> $seriesOnline
     * @param list<AgendaRow> $ongoing
     * @param list<ArchiveYear> $archiveYears all public past, newest first
     * @param list<CountryCount> $countryCounts the sheet: every country with an in-person occurrence
     * @param list<CountryCount> $chipCountries ≤ CHIP_COUNTRIES with upcoming dates, + the active scope's country
     * @param list<array<string, mixed>> $index the search and calendar index (EventsIndexFactory), position = id - full
     *     entries, what the server reads (`?q=` search, the search results)
     * @param list<string> $itemListUrls
     * @param list<CountryRegionGroup> $regions
     * @param list<array<string, mixed>> $shippedIndex the same index as the page ships it (EventsIndexFactory::compact(),
     *     rebuilt in the browser by expandEventsIndex() of assets/events_index.js)
     */
    public function __construct(
        public EventsSummary $summary,
        public array $yourEvents,
        public array $live,
        public array $months,
        public array $tba,
        public array $seriesInPerson,
        public array $seriesOnline,
        public array $ongoing,
        public array $archiveYears,
        public array $countryCounts,
        public array $chipCountries,
        public null|CountryCount $homeCountry,
        public int $onlineUpcoming,
        public int $everywhereUpcoming,
        public int $organizedCount,
        public EventsScope $scope,
        public int $scopeUpcoming,
        public null|ArchiveLine $scopeLast,
        public array $index,
        public array $itemListUrls,
        public array $regions,
        public array $shippedIndex = [],
    ) {
    }
}
