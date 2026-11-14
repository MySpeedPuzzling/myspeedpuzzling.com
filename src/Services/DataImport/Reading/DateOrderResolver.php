<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DateOrderResolution;
use SpeedPuzzling\Web\Value\DataImport\DateOrderState;
use SpeedPuzzling\Web\Value\DataImport\DateProblem;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DataImport\ShortDateDecision;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * Settles what one column's dates leave open (docs/features/data-import/README.md "Dates": "text dates start with
 * every order allowed and lose the orders a value contradicts (a 13 in the first place settles it); still open = one
 * question"; docs/features/data-import/value-readers.md "The column decides").
 *
 * Two questions, in this order:
 *
 * 1. **Short dates** ("Jan 23", "10/23"): is the last number a day or a two-digit year? A value that is only one of
 *    them settles the column ("Feb 30" cannot be a day, "25/3" and "Jan 5" cannot be a month of a year); when every
 *    value can be both, the player is asked once - month-only data must never become exact days by itself.
 * 2. **The order** of day and month in dates written in numbers. Only a contradiction removes an order - a value that
 *    is no real date in it. One exception: the year-first reading of two-digit dates ("30-12-23" as 23 December
 *    2030) goes when it puts a row into the future and another order that is left does not. Otherwise a date in the
 *    future removes nothing (people mistype years); it only makes the other order the one offered first.
 *
 * And one thing it never does: read a column in two orders. Text dates that are all day-first beside typed dates
 * that all have a day of 12 or less are what a spreadsheet leaves after reading day-first text as month-first - that
 * is a question (DateOrderState::TypedDatesInDoubt), not two silent orders.
 */
final readonly class DateOrderResolver
{
    private const int EXAMPLE_ROWS = 5;

    /** Fewer short dates do not show a march through the months */
    private const int ROWS_TO_SEE_A_MARCH = 3;

    /**
     * @param list<ReadDate> $dates Every row of the column, in order - a row's index is how it is named in the result
     * @param null|ShortDateMeaning $shortDatesAnswer The player's answer to the short dates' question, once given
     */
    public function resolve(array $dates, null|ShortDateMeaning $shortDatesAnswer = null): DateOrderResolution
    {
        $shortDates = $this->shortDates($dates, $shortDatesAnswer);

        if ($shortDates->meaning !== null) {
            $meaning = $shortDates->meaning;
            $dates = array_map(static fn (ReadDate $date): ReadDate => $date->asShort($meaning), $dates);
        }

        // A short date still open between a day and a year has no orders: it waits for its own answer first
        $inNumbers = array_filter($dates, static fn (ReadDate $date): bool => $date->possibleOrders !== []);

        if ($inNumbers === []) {
            return new DateOrderResolution(DateOrderState::NotNeeded, $shortDates);
        }

        $rowsByOrder = [];
        $fitting = [];

        foreach (DateOrder::cases() as $order) {
            $rowsByOrder[$order->value] = count(array_filter($inNumbers, static fn (ReadDate $date): bool => $date->allows($order)));

            if ($rowsByOrder[$order->value] === count($inNumbers)) {
                $fitting[] = $order;
            }
        }

        if ($fitting === []) {
            $mostRows = $rowsByOrder;
            arsort($mostRows);
            $most = DateOrder::from((string) array_key_first($mostRows));

            return new DateOrderResolution(
                DateOrderState::Mixed,
                $shortDates,
                rowsByOrder: $rowsByOrder,
                exampleRows: array_slice(array_keys(array_filter($inNumbers, static fn (ReadDate $date): bool => $date->allows($most) === false)), 0, self::EXAMPLE_ROWS),
                rows: count($inNumbers),
            );
        }

        $fitting = self::withoutAYearFirstFuture($fitting, $inNumbers);

        // Orders that read every value as the same day are one answer ("3/13" is 13 March month-first and year-first)
        $choices = [];
        $days = [];

        foreach ($fitting as $order) {
            $ofOrder = array_map(static fn (ReadDate $date): null|string => $date->dayIn($order), $inNumbers);

            if (in_array($ofOrder, $days, true) === false) {
                $choices[] = $order;
                $days[] = $ofOrder;
            }
        }

        if (count($choices) > 1) {
            $differing = array_keys(array_filter(
                $inNumbers,
                static fn (ReadDate $date): bool => count(array_unique(array_map(static fn (DateOrder $order): null|string => $date->dayIn($order), $choices))) > 1,
            ));

            return new DateOrderResolution(
                DateOrderState::Ambiguous,
                $shortDates,
                $choices,
                self::withoutProblems($choices, $inNumbers),
                $rowsByOrder,
                array_slice($differing, 0, self::EXAMPLE_ROWS),
                count($inNumbers),
            );
        }

        $typedInDoubt = self::typedDatesInDoubt($choices[0], $dates, $inNumbers);

        if ($typedInDoubt !== []) {
            return new DateOrderResolution(
                DateOrderState::TypedDatesInDoubt,
                $shortDates,
                $choices,
                rowsByOrder: $rowsByOrder,
                exampleRows: array_slice($typedInDoubt, 0, self::EXAMPLE_ROWS),
                rows: count($inNumbers),
            );
        }

        return new DateOrderResolution(DateOrderState::Resolved, $shortDates, $choices, rowsByOrder: $rowsByOrder, rows: count($inNumbers));
    }

    /**
     * @param list<ReadDate> $dates
     */
    private function shortDates(array $dates, null|ShortDateMeaning $answer): ShortDateDecision
    {
        $short = array_filter($dates, static fn (ReadDate $date): bool => $date->possibleShortMeanings !== []);

        if ($short === []) {
            return new ShortDateDecision(DateOrderState::NotNeeded);
        }

        $rows = [];

        foreach (ShortDateMeaning::cases() as $meaning) {
            $rows[$meaning->value] = array_keys(array_filter($short, static fn (ReadDate $date): bool => in_array($meaning, $date->possibleShortMeanings, true)));
        }

        $canBeDays = count($rows[ShortDateMeaning::DayOfMonth->value]) === count($short);
        $canBeYears = count($rows[ShortDateMeaning::MonthOfYear->value]) === count($short);

        if ($answer !== null) {
            return new ShortDateDecision(DateOrderState::Resolved, $answer, rows: count($short));
        }

        if ($canBeDays !== $canBeYears) {
            return new ShortDateDecision(
                DateOrderState::Resolved,
                $canBeDays ? ShortDateMeaning::DayOfMonth : ShortDateMeaning::MonthOfYear,
                rows: count($short),
            );
        }

        if ($canBeDays === false) {
            // Some can only be a day, some only a year: the rows that do not go the way most go
            $days = $rows[ShortDateMeaning::DayOfMonth->value];
            $years = $rows[ShortDateMeaning::MonthOfYear->value];
            $minority = count($days) >= count($years) ? array_diff(array_keys($short), $days) : array_diff(array_keys($short), $years);

            return new ShortDateDecision(DateOrderState::Mixed, exampleRows: array_slice(array_values($minority), 0, self::EXAMPLE_ROWS), rows: count($short));
        }

        return new ShortDateDecision(
            DateOrderState::Ambiguous,
            suggested: self::suggestedMeaning(array_values($short)),
            exampleRows: array_slice(array_keys($short), 0, self::EXAMPLE_ROWS),
            rows: count($short),
        );
    }

    /**
     * What to offer first when every short date can be a day and a year. Read as months of a year, values that march
     * month by month ("Jan 23, Feb 23, Mar 23", "10/23, 11/23, 12/23, 01/24") are months. Values that would be
     * months of a year still to come are days.
     *
     * @param list<ReadDate> $short
     */
    private static function suggestedMeaning(array $short): null|ShortDateMeaning
    {
        $months = [];
        $inTheFuture = false;

        foreach ($short as $date) {
            $asMonth = $date->shortReading(ShortDateMeaning::MonthOfYear);

            if ($asMonth === null || $asMonth->date === null) {
                return null;
            }

            $months[] = (int) $asMonth->date->format('Y') * 12 + (int) $asMonth->date->format('n');
            $inTheFuture = $inTheFuture || in_array(DateProblem::InTheFuture, $asMonth->problems, true);
        }

        if ($inTheFuture) {
            return ShortDateMeaning::DayOfMonth;
        }

        if (count($months) < self::ROWS_TO_SEE_A_MARCH) {
            return null;
        }

        $steps = [];

        for ($i = 1; $i < count($months); $i++) {
            $steps[] = $months[$i] - $months[$i - 1];
        }

        $forward = array_diff($steps, [0, 1]) === [] && in_array(1, $steps, true);
        $backward = array_diff($steps, [0, -1]) === [] && in_array(-1, $steps, true);

        return $forward || $backward ? ShortDateMeaning::MonthOfYear : null;
    }

    /**
     * The year-first order of two-digit dates goes when it puts a row into the future and another order left does not.
     *
     * @param non-empty-list<DateOrder> $fitting
     * @param array<int, ReadDate> $dates
     * @return non-empty-list<DateOrder>
     */
    private static function withoutAYearFirstFuture(array $fitting, array $dates): array
    {
        if (count($fitting) < 2 || in_array(DateOrder::YearMonthDay, $fitting, true) === false) {
            return $fitting;
        }

        $hasFuture = static function (DateOrder $order) use ($dates): bool {
            foreach ($dates as $date) {
                if (in_array(DateProblem::InTheFuture, $date->problemsIn($order), true)) {
                    return true;
                }
            }

            return false;
        };

        if ($hasFuture(DateOrder::YearMonthDay) === false) {
            return $fitting;
        }

        $others = array_values(array_filter($fitting, static fn (DateOrder $order): bool => $order !== DateOrder::YearMonthDay));

        foreach ($others as $other) {
            if ($hasFuture($other) === false) {
                return $others;
            }
        }

        return $fitting;
    }

    /**
     * The typed dates of a column whose text dates all follow $order and none of them could be read the other way
     * (every one has a day above 12), when every typed day has a day of 12 or less: the rows of those typed dates.
     * Empty when the pattern is not there.
     *
     * @param list<ReadDate> $column
     * @param array<int, ReadDate> $inNumbers
     * @return list<int>
     */
    private static function typedDatesInDoubt(DateOrder $order, array $column, array $inNumbers): array
    {
        $other = match ($order) {
            DateOrder::DayMonthYear => DateOrder::MonthDayYear,
            DateOrder::MonthDayYear => DateOrder::DayMonthYear,
            DateOrder::YearMonthDay => null,
        };

        if ($other === null) {
            return [];
        }

        foreach ($inNumbers as $date) {
            if ($date->allows($other)) {
                return [];
            }
        }

        $typed = array_filter(
            $column,
            static fn (ReadDate $date): bool => $date->typed && $date->date !== null && $date->precision === DatePrecision::Day,
        );

        foreach ($typed as $date) {
            if ((int) $date->date?->format('j') > 12) {
                return [];
            }
        }

        return array_keys($typed);
    }

    /**
     * The one order under which no value is a date in the future or before 1950, when every other order has such.
     *
     * @param list<DateOrder> $choices
     * @param array<int, ReadDate> $dates
     */
    private static function withoutProblems(array $choices, array $dates): null|DateOrder
    {
        $clean = array_values(array_filter($choices, static function (DateOrder $order) use ($dates): bool {
            foreach ($dates as $date) {
                if ($date->problemsIn($order) !== []) {
                    return false;
                }
            }

            return true;
        }));

        return count($clean) === 1 ? $clean[0] : null;
    }
}
