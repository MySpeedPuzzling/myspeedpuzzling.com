<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class EventsListing
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $timePeriod = 'all';

    #[LiveProp(writable: true, url: true)]
    public bool $onlineOnly = false;

    #[LiveProp(writable: true, url: true)]
    public string $country = '';

    #[LiveProp(writable: true, url: true)]
    public bool $showCalendar = false;

    #[LiveProp(writable: true)]
    public int $calendarYear = 0;

    #[LiveProp(writable: true)]
    public int $calendarMonth = 0;

    #[LiveProp(writable: true)]
    public null|string $selectedDay = null;

    /** @var null|array<CompetitionEvent> */
    private null|array $cachedItems = null;

    /** @var null|array<string, list<CompetitionEvent>> */
    private null|array $cachedEventsByDay = null;

    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionSeries $getCompetitionSeries,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private ClockInterface $clock,
    ) {
    }

    #[PostMount]
    public function initCalendarMonth(): void
    {
        if ($this->calendarYear === 0 || $this->calendarMonth === 0) {
            $now = $this->clock->now();
            $this->calendarYear = (int) $now->format('Y');
            $this->calendarMonth = (int) $now->format('n');
        }
    }

    /**
     * @return array<CompetitionEvent>
     */
    public function getItems(): array
    {
        if ($this->cachedItems !== null) {
            return $this->cachedItems;
        }

        $allowedPeriods = ['all', 'live', 'upcoming', 'past'];
        $timePeriod = in_array($this->timePeriod, $allowedPeriods, true) ? $this->timePeriod : 'all';

        $this->cachedItems = $this->getCompetitionEvents->search(
            timePeriod: $timePeriod,
            onlineOnly: $this->onlineOnly,
            country: $this->country !== '' ? $this->country : null,
        );

        return $this->cachedItems;
    }

    /**
     * @return array<CompetitionSeriesOverview>
     */
    public function getSeriesItems(): array
    {
        $approved = $this->getCompetitionSeries->allApproved();
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile?->isAdmin !== true) {
            return $approved;
        }

        $unapproved = $this->getCompetitionSeries->allUnapproved();

        return array_merge($approved, $unapproved);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getCountryChoicesGroupedByRegion(): array
    {
        $centralEurope = [
            CountryCode::cz, CountryCode::sk, CountryCode::pl, CountryCode::hu,
            CountryCode::at, CountryCode::si, CountryCode::ch, CountryCode::li,
        ];

        $westernEurope = [
            CountryCode::de, CountryCode::fr, CountryCode::nl, CountryCode::be,
            CountryCode::lu, CountryCode::ie, CountryCode::gb, CountryCode::mc,
        ];

        $southernEurope = [
            CountryCode::es, CountryCode::pt, CountryCode::it, CountryCode::gr,
            CountryCode::hr, CountryCode::ba, CountryCode::rs, CountryCode::me,
            CountryCode::mk, CountryCode::al, CountryCode::mt, CountryCode::cy,
        ];

        $northernEurope = [
            CountryCode::se, CountryCode::no, CountryCode::dk, CountryCode::fi,
            CountryCode::is, CountryCode::ee, CountryCode::lv, CountryCode::lt,
        ];

        $easternEurope = [
            CountryCode::ro, CountryCode::bg, CountryCode::ua, CountryCode::md,
            CountryCode::by,
        ];

        $northAmerica = [
            CountryCode::us, CountryCode::ca, CountryCode::mx,
        ];

        $groups = [
            $this->translator->trans('sell_swap_list.settings.region.central_europe') => $centralEurope,
            $this->translator->trans('sell_swap_list.settings.region.western_europe') => $westernEurope,
            $this->translator->trans('sell_swap_list.settings.region.southern_europe') => $southernEurope,
            $this->translator->trans('sell_swap_list.settings.region.northern_europe') => $northernEurope,
            $this->translator->trans('sell_swap_list.settings.region.eastern_europe') => $easternEurope,
            $this->translator->trans('sell_swap_list.settings.region.north_america') => $northAmerica,
        ];

        $usedCodes = [];
        foreach ($groups as $countries) {
            foreach ($countries as $country) {
                $usedCodes[] = $country->name;
            }
        }

        $restOfWorld = [];
        foreach (CountryCode::cases() as $country) {
            if (!in_array($country->name, $usedCodes, true)) {
                $restOfWorld[] = $country;
            }
        }

        $groups[$this->translator->trans('sell_swap_list.settings.region.rest_of_world')] = $restOfWorld;

        $choices = [];
        foreach ($groups as $groupName => $countries) {
            foreach ($countries as $country) {
                $choices[$groupName][$country->name] = $country->value;
            }
        }

        return $choices;
    }

    #[LiveAction]
    public function toggleCalendar(): void
    {
        $this->showCalendar = $this->showCalendar === false;

        if ($this->showCalendar === false) {
            $this->selectedDay = null;
        }
    }

    #[LiveAction]
    public function prevMonth(): void
    {
        $this->shiftMonth(-1);
    }

    #[LiveAction]
    public function nextMonth(): void
    {
        $this->shiftMonth(1);
    }

    #[LiveAction]
    public function selectDay(#[LiveArg] string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        // Toggle off when the already-selected day is clicked again.
        $this->selectedDay = $this->selectedDay === $date ? null : $date;
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedDay = null;
    }

    /**
     * Map of 'Y-m-d' => events active on that day. Multi-day events appear on
     * every day they span, so the calendar shows the full duration.
     *
     * @return array<string, list<CompetitionEvent>>
     */
    public function getEventsByDay(): array
    {
        if ($this->cachedEventsByDay !== null) {
            return $this->cachedEventsByDay;
        }

        $byDay = [];

        foreach ($this->getItems() as $event) {
            $start = $event->dateFrom ?? $event->dateTo;
            $end = $event->dateTo ?? $event->dateFrom;

            if ($start === null || $end === null) {
                continue;
            }

            $cursor = $start->setTime(0, 0);
            $last = ($end < $start ? $start : $end)->setTime(0, 0);

            // Safety cap: an event spanning more than a year is almost certainly bad data.
            $guard = 0;
            while ($cursor <= $last && $guard < 400) {
                $byDay[$cursor->format('Y-m-d')][] = $event;
                $cursor = $cursor->modify('+1 day');
                $guard++;
            }
        }

        $this->cachedEventsByDay = $byDay;

        return $this->cachedEventsByDay;
    }

    /**
     * Month grid as a flat list of cells (Monday-start, padded to full weeks).
     *
     * @return list<array{date: null|DateTimeImmutable, events: list<CompetitionEvent>, inMonth: bool, isToday: bool, isSelected: bool}>
     */
    public function getCalendarCells(): array
    {
        $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->calendarYear, $this->calendarMonth));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        // ISO day-of-week: Mon=1..Sun=7, converted to leading blanks (Mon-start week).
        $leading = ((int) $firstOfMonth->format('N')) - 1;

        $today = $this->clock->now()->format('Y-m-d');
        $eventsByDay = $this->getEventsByDay();

        $cells = [];

        for ($i = 0; $i < $leading; $i++) {
            $cells[] = $this->emptyCell();
        }

        for ($day = $firstOfMonth; $day <= $lastOfMonth; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $cells[] = [
                'date' => $day,
                'events' => $eventsByDay[$key] ?? [],
                'inMonth' => true,
                'isToday' => $key === $today,
                'isSelected' => $key === $this->selectedDay,
            ];
        }

        // Pad trailing blanks so rows of 7 stay complete (grid height is stable).
        while (count($cells) % 7 !== 0) {
            $cells[] = $this->emptyCell();
        }

        return $cells;
    }

    /**
     * @return list<CompetitionEvent>
     */
    public function getSelectedDayEvents(): array
    {
        if ($this->selectedDay === null) {
            return [];
        }

        return $this->getEventsByDay()[$this->selectedDay] ?? [];
    }

    private function shiftMonth(int $direction): void
    {
        if ($this->calendarYear === 0 || $this->calendarMonth === 0) {
            $this->initCalendarMonth();
        }

        $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->calendarYear, $this->calendarMonth));
        $date = $date->modify(sprintf('%+d month', $direction));

        $this->calendarYear = (int) $date->format('Y');
        $this->calendarMonth = (int) $date->format('n');
    }

    /**
     * @return array{date: null, events: list<CompetitionEvent>, inMonth: false, isToday: false, isSelected: false}
     */
    private function emptyCell(): array
    {
        return [
            'date' => null,
            'events' => [],
            'inMonth' => false,
            'isToday' => false,
            'isSelected' => false,
        ];
    }
}
