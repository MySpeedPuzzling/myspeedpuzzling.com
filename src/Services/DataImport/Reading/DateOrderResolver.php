<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DateOrderResolution;
use SpeedPuzzling\Web\Value\DataImport\DateOrderState;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;

/**
 * Settles the day/month order of one column's dates written in numbers (docs/features/data-import/README.md "Dates":
 * "text dates start with every order allowed and lose the orders a value contradicts (a 13 in the first place settles
 * it); still open = one question"; docs/features/data-import/value-readers.md "The order of a column").
 *
 * Only a contradiction removes an order - a value that is no real date in it. A date in the future never does (people
 * mistype years); it only makes the other order the one offered first.
 */
final readonly class DateOrderResolver
{
    private const int EXAMPLE_ROWS = 5;

    /**
     * @param list<ReadDate> $dates Every row of the column, in order - a row's index is how it is named in the result
     */
    public function resolve(array $dates): DateOrderResolution
    {
        $inNumbers = array_filter($dates, static fn (ReadDate $date): bool => $date->possibleOrders !== []);

        if ($inNumbers === []) {
            return new DateOrderResolution(DateOrderState::NotNeeded);
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
            arsort($rowsByOrder);
            $most = DateOrder::from((string) array_key_first($rowsByOrder));

            return new DateOrderResolution(
                DateOrderState::Mixed,
                rowsByOrder: self::inOrderOfTheCases($rowsByOrder),
                exampleRows: array_slice(array_keys(array_filter($inNumbers, static fn (ReadDate $date): bool => $date->allows($most) === false)), 0, self::EXAMPLE_ROWS),
                rows: count($inNumbers),
            );
        }

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

        if (count($choices) === 1) {
            return new DateOrderResolution(DateOrderState::Resolved, $choices, rowsByOrder: $rowsByOrder, rows: count($inNumbers));
        }

        $differing = array_keys(array_filter(
            $inNumbers,
            static fn (ReadDate $date): bool => count(array_unique(array_map(static fn (DateOrder $order): null|string => $date->dayIn($order), $choices))) > 1,
        ));

        return new DateOrderResolution(
            DateOrderState::Ambiguous,
            $choices,
            self::withoutProblems($choices, $inNumbers),
            $rowsByOrder,
            array_slice($differing, 0, self::EXAMPLE_ROWS),
            count($inNumbers),
        );
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

    /**
     * @param array<string, int> $rowsByOrder
     * @return array<string, int>
     */
    private static function inOrderOfTheCases(array $rowsByOrder): array
    {
        $ordered = [];

        foreach (DateOrder::cases() as $order) {
            $ordered[$order->value] = $rowsByOrder[$order->value] ?? 0;
        }

        return $ordered;
    }
}
