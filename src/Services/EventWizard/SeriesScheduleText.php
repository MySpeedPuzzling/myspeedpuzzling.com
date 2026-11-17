<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\FormData\EventWizardDatesFormData;
use SpeedPuzzling\Web\FormType\EventWizardBasicsFormType;
use SpeedPuzzling\Web\Value\EditionDateRuleKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A series' "When it happens" (CompetitionSeries::$schedule - free text shown as typed, in no locale of its own) from the
 * wizard's Dates rule, in the creator's language: "Every Tuesday at 7:00 PM", "First Saturday of the month at 10:00",
 * "Last Friday of the month"; picked days only say their start time ("At 7:00 PM on each date"), nothing without one.
 * Filled only while the series has none (CompetitionSeries::scheduleFromDatesRule()) - never over a typed text.
 */
readonly final class SeriesScheduleText
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function of(EventWizardDatesFormData $rule, string $locale): null|string
    {
        $time = $rule->hasRounds() ? self::time(EventWizardBasicsFormType::startTime($rule->startTime ?? ''), $locale) : null;

        if ($rule->how === AddEditionsFormData::HOW_PICK) {
            return $time !== null
                ? $this->trans('picked_at_time', ['%time%' => $time], $locale)
                : null;
        }

        $weekday = $rule->weekday;

        if ($rule->rule === null || $weekday === null || $weekday < 1 || $weekday > 7) {
            return null;
        }

        $when = match ($rule->rule) {
            EditionDateRuleKind::Weekly => $this->trans('weekly.' . $weekday, [], $locale),
            EditionDateRuleKind::NthWeekday => $this->trans('nth', [
                '%ordinal%' => $this->trans('ordinal.' . max(1, min(4, $rule->nth ?? 1)), [], $locale),
                '%weekday%' => $this->trans('weekday.' . $weekday, [], $locale),
            ], $locale),
            EditionDateRuleKind::LastWeekday => $this->trans('last', ['%weekday%' => $this->trans('weekday.' . $weekday, [], $locale)], $locale),
        };

        $text = $time !== null ? $this->trans('at_time', ['%when%' => $when, '%time%' => $time], $locale) : $when;

        return mb_substr($text, 0, CompetitionSeries::SCHEDULE_MAX_LENGTH);
    }

    /**
     * "19:00" as the language writes a time of day ("7:00 PM" in English)
     */
    private static function time(null|string $startTime, string $locale): null|string
    {
        if ($startTime === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $utc);
        $formatted = $formatter->format(new DateTimeImmutable('2000-01-03 ' . $startTime, $utc));

        return is_string($formatted) ? $formatted : $startTime;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans('event_wizard_more.when.' . $key, $parameters, locale: $locale);
    }
}
