<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\EventWizardDatesFormData;
use SpeedPuzzling\Web\Results\EventWizardSeriesEdition;
use SpeedPuzzling\Web\Value\EditionDateRuleKind;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * "Add the next dates" (W3) asks only what the series' last dates do not say (Jan's smart forms): the rule when the last
 * dates follow one (every week on a weekday, the Nth or the last weekday of every month), starting the day after the
 * last one, and the last date's round - who competes, the time limit, the start time. Nothing inferable = the form's
 * defaults.
 */
final class EventWizardDatesPrefill
{
    private const int DATES_READ = 4;

    /**
     * @param list<EventWizardSeriesEdition> $editions the series' editions by day (GetEventWizardSeries::editions())
     */
    public static function apply(EventWizardDatesFormData $data, array $editions): void
    {
        $dated = array_values(array_filter($editions, static fn (EventWizardSeriesEdition $edition): bool => $edition->dateFrom !== null));

        if ($dated === []) {
            return;
        }

        $last = $dated[array_key_last($dated)];
        assert($last->dateFrom !== null);
        $data->starting = $last->dateFrom->modify('+1 day');
        $data->weekday = (int) $last->dateFrom->format('N');

        $days = array_map(static fn (EventWizardSeriesEdition $edition): DateTimeImmutable => $edition->dateFrom ?? $last->dateFrom, array_slice($dated, -self::DATES_READ));
        self::applyRule($data, $days);

        if ($last->roundsCount === 0) {
            $data->withRounds = false;

            return;
        }

        $data->withRounds = true;
        $data->category = $last->roundCategory ?? $data->category;
        $data->teamSize = $last->roundTeamSize;
        $data->minutesLimit = $last->roundMinutesLimit ?? $data->minutesLimit;

        if ($last->roundStartsAt !== null && $last->roundStartTimeKnown) {
            $data->startTime = RoundTimezone::toLocal($last->roundStartsAt, RoundTimezone::resolve($last->roundTimezone))->format('H:i');
        }
    }

    /**
     * @param list<DateTimeImmutable> $days at least one, in order
     */
    private static function applyRule(EventWizardDatesFormData $data, array $days): void
    {
        if (count($days) < 2) {
            return;
        }

        $weekly = true;
        $sameWeekday = true;
        $monthly = true;

        for ($i = 1; $i < count($days); $i++) {
            $weekly = $weekly && $days[$i - 1]->diff($days[$i])->days === 7;
            $sameWeekday = $sameWeekday && $days[$i - 1]->format('N') === $days[$i]->format('N');
            $months = ((int) $days[$i]->format('Y') * 12 + (int) $days[$i]->format('n')) - ((int) $days[$i - 1]->format('Y') * 12 + (int) $days[$i - 1]->format('n'));
            $monthly = $monthly && $months === 1;
        }

        if ($weekly) {
            $data->how = EventWizardDatesFormData::HOW_REPEAT;
            $data->rule = EditionDateRuleKind::Weekly;

            return;
        }

        if ($sameWeekday === false || $monthly === false) {
            return;
        }

        $nths = array_unique(array_map(static fn (DateTimeImmutable $day): int => intdiv((int) $day->format('j') - 1, 7) + 1, $days));
        $allLast = array_filter($days, static fn (DateTimeImmutable $day): bool => (int) $day->format('j') + 7 > (int) $day->format('t')) === $days;

        if (count($nths) === 1 && $nths[array_key_first($nths)] <= 4) {
            $data->how = EventWizardDatesFormData::HOW_REPEAT;
            $data->rule = EditionDateRuleKind::NthWeekday;
            $data->nth = $nths[array_key_first($nths)];
        } elseif ($allLast) {
            $data->how = EventWizardDatesFormData::HOW_REPEAT;
            $data->rule = EditionDateRuleKind::LastWeekday;
        }
    }
}
