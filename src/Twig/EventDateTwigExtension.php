<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\EventDateFormatter;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `event_dates(dateFrom, dateTo)` - an event's day(s) in the page's locale, compact (EventDateFormatter).
 * `date|events_date('yMMMd')` and `events_date_range(from, to, 'MMMd')` - the events pages' dates from an ICU skeleton
 * in the page's locale (EventsPageDates).
 */
final class EventDateTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private EventDateFormatter $eventDateFormatter,
        readonly private EventsPageDates $eventsPageDates,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_dates', $this->eventDateFormatter->format(...)),
            new TwigFunction('events_date_range', $this->eventsPageDates->range(...)),
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
