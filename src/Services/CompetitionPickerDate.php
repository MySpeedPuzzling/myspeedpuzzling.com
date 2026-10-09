<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;

/**
 * One style for every date of the add-time "Competition / event" picker (docs/features/events-page/
 * high-frequency-series.md "The form"): the option cards (one-time events, a series' next or last date, editions), the
 * preview line and the short edition list. The events pages' ICU skeletons in the page's language (EventsPageDates):
 * a day with its weekday ("Tue 13 Oct"), several days as one compact range ("10–11 Oct", "29 Sep – 8 Nov"), the year
 * only when a day is not in the current one ("Tue, 1 Apr 2025", "30 Oct – 2 Nov 2025").
 *
 * Templates: `picker_date(from, to)` (EventDateTwigExtension).
 */
readonly final class CompetitionPickerDate
{
    public function __construct(
        private EventsPageDates $dates,
        private ClockInterface $clock,
    ) {
    }

    public function format(DateTimeInterface $from, null|DateTimeInterface $to = null): string
    {
        $thisYear = $this->clock->now()->format('Y');
        $withYear = $from->format('Y') !== $thisYear || ($to !== null && $to->format('Y') !== $thisYear);

        if ($to === null || $to->format('Y-m-d') === $from->format('Y-m-d')) {
            return $this->dates->format($from, $withYear ? 'yMMMEd' : 'MMMEd');
        }

        return $this->dates->range($from, $to, $withYear ? 'yMMMd' : 'MMMd');
    }
}
