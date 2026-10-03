<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\EventDateFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `event_dates(dateFrom, dateTo)` - an event's day(s) in the page's locale, compact (EventDateFormatter).
 */
final class EventDateTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private EventDateFormatter $eventDateFormatter,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_dates', $this->eventDateFormatter->format(...)),
        ];
    }
}
