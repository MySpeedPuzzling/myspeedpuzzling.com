<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlTimeZone;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * A moment as the people of an event read it: in the event's zone, in the page's language, the zone named
 * ("Saturday, October 24, 2026 at 8:15 AM (Chicago Time)"). For moments an organiser acts on - a round's start,
 * a secret puzzle's reveal - where a time without its zone could mislead. Twig: zoned_datetime(), timezone_name().
 */
readonly final class ZonedDateTimeFormatter
{
    public function __construct(
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function format(DateTimeImmutable $moment, string $timezone): string
    {
        $formatter = new IntlDateFormatter(
            $this->localeSwitcher->getLocale(),
            IntlDateFormatter::FULL,
            IntlDateFormatter::SHORT,
            $timezone,
        );

        $formatted = $formatter->format($moment);

        if ($formatted === false) {
            $formatted = $moment->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
        }

        return $formatted . ' (' . $this->timezoneName($timezone) . ')';
    }

    public function timezoneName(string $timezone): string
    {
        return IntlTimeZone::createTimeZone($timezone)
            ->getDisplayName(false, IntlTimeZone::DISPLAY_GENERIC_LOCATION, $this->localeSwitcher->getLocale());
    }
}
