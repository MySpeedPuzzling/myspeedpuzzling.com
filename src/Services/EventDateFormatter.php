<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Compact, locale-aware day(s) of an event for labels and sentences: "Oct 10", "Oct 10–11", "10–11 Oct",
 * "10. 10.", "10月10日–11日" - the order and the month's spelling come from ICU's best pattern for the locale,
 * the year only shows when the event does not end this year.
 */
readonly final class EventDateFormatter
{
    public function __construct(
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function format(DateTimeImmutable $dateFrom, null|DateTimeImmutable $dateTo = null, null|string $locale = null): string
    {
        $locale ??= $this->translator->getLocale();
        $dateTo ??= $dateFrom;
        $withYear = $dateTo->format('Y') !== $this->clock->now()->format('Y');
        $patterns = new IntlDatePatternGenerator($locale);
        $dayAndMonth = (string) $patterns->getBestPattern($withYear ? 'yMMMd' : 'MMMd');

        if ($dateFrom->format('Y-m-d') === $dateTo->format('Y-m-d')) {
            return self::formatted($dateFrom, $dayAndMonth, $locale);
        }

        if ($withYear === false && $dateFrom->format('Y-m') === $dateTo->format('Y-m')) {
            $dayOnly = (string) $patterns->getBestPattern('d');

            return self::dayComesFirst($dayAndMonth)
                ? self::formatted($dateFrom, $dayOnly, $locale) . '–' . self::formatted($dateTo, $dayAndMonth, $locale)
                : self::formatted($dateFrom, $dayAndMonth, $locale) . '–' . self::formatted($dateTo, $dayOnly, $locale);
        }

        return self::formatted($dateFrom, $dayAndMonth, $locale) . ' – ' . self::formatted($dateTo, $dayAndMonth, $locale);
    }

    private static function formatted(DateTimeImmutable $date, string $pattern, string $locale): string
    {
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );
        $formatted = $formatter->format($date);

        return is_string($formatted) ? $formatted : $date->format('j. n.');
    }

    private static function dayComesFirst(string $pattern): bool
    {
        // Quoted literals never hold the d/M fields
        $fields = (string) preg_replace("/'[^']*'/", '', $pattern);
        $day = strpos($fields, 'd');
        $month = strcspn($fields, 'ML');

        return $day !== false && $day < $month;
    }
}
