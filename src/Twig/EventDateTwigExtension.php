<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\CompetitionPickerDate;
use SpeedPuzzling\Web\Services\EventDateFormatter;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Value\EventTime;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `event_dates(dateFrom, dateTo)` - an event's day(s) in the page's locale, compact (EventDateFormatter).
 * `date|events_date('yMMMd')` and `events_date_range(from, to, 'MMMd')` - the events pages' dates from an ICU skeleton
 * in the page's locale (EventsPageDates).
 * `picker_date(from, to)` - a date of the add-time "Competition / event" picker (CompetitionPickerDate).
 * `event_time(time)` - a start time in the event's zone, the zone named: "22:00 Eastern Time" (EventTime,
 * `event_detail.time.in_zone`); `event_time_parts(time)` - the two values for markup of their own
 * (event_parts/_event_time.html.twig).
 */
final class EventDateTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private EventDateFormatter $eventDateFormatter,
        readonly private EventsPageDates $eventsPageDates,
        readonly private CompetitionPickerDate $competitionPickerDate,
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function eventTime(EventTime $time): string
    {
        $parts = $this->eventTimeParts($time);

        return $this->translator->trans('event_detail.time.in_zone', ['%time%' => $parts['time'], '%zone%' => $parts['zone']]);
    }

    /**
     * @return array{time: string, zone: string}
     */
    public function eventTimeParts(EventTime $time): array
    {
        return [
            'time' => $this->eventsPageDates->time($time->instant, $time->zone),
            'zone' => $this->eventsPageDates->zoneName($time->zone),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_dates', $this->eventDateFormatter->format(...)),
            new TwigFunction('events_date_range', $this->eventsPageDates->range(...)),
            new TwigFunction('picker_date', $this->competitionPickerDate->format(...)),
            new TwigFunction('event_time', $this->eventTime(...)),
            new TwigFunction('event_time_parts', $this->eventTimeParts(...)),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('events_date', $this->eventsPageDates->format(...)),
        ];
    }
}
