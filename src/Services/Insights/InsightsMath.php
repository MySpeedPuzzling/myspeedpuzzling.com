<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

/**
 * The small statistics every Insights number is built from - pure, static, no state. Shared by the profile card
 * (InsightsProfileCalculator) and the page's builders so "median", "quantile" and "your usual" mean one thing.
 */
final class InsightsMath
{
    public const int DAY = 86_400;

    /**
     * @param list<float> $values not empty
     */
    public static function median(array $values): float
    {
        sort($values);

        return self::medianOfSorted($values);
    }

    /**
     * @param list<float> $sorted not empty, ascending
     */
    public static function medianOfSorted(array $sorted): float
    {
        $count = count($sorted);
        $middle = $count >> 1;

        return $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Linear interpolation between the closest ranks, as the prototype's quant()
     *
     * @param list<float> $values not empty
     */
    public static function quantile(array $values, float $q): float
    {
        sort($values);

        return self::quantileOfSorted($values, $q);
    }

    /**
     * @param list<float> $sorted not empty, ascending
     */
    public static function quantileOfSorted(array $sorted, float $q): float
    {
        $position = (count($sorted) - 1) * $q;
        $low = (int) floor($position);
        $high = (int) ceil($position);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($position - $low);
    }

    /**
     * First index whose value is >= $value
     *
     * @param list<float> $sorted ascending
     */
    public static function insertionPoint(array $sorted, float $value): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The rolling median "your usual": for every query moment, the median of the values whose moment lies within
     * ±$halfWindow seconds of it - null when none does. One sorted window slides along both lists (each ascending by
     * moment), so it costs O(n · w) instead of sorting a window per query (the naive version is O(n² log n)).
     *
     * @param list<array{0: int, 1: float}> $points [moment, value], ascending by moment
     * @param list<int> $moments ascending
     * @return list<null|float> one per moment, in the same order
     */
    public static function rollingMedian(array $points, array $moments, int $halfWindow): array
    {
        $medians = [];
        /** @var list<float> $window sorted */
        $window = [];
        $nextToAdd = 0;
        $nextToRemove = 0;
        $pointCount = count($points);

        foreach ($moments as $moment) {
            while ($nextToAdd < $pointCount && $points[$nextToAdd][0] <= $moment + $halfWindow) {
                $value = $points[$nextToAdd][1];
                array_splice($window, self::insertionPoint($window, $value), 0, [$value]);
                $nextToAdd++;
            }

            while ($nextToRemove < $nextToAdd && $points[$nextToRemove][0] < $moment - $halfWindow) {
                $value = $points[$nextToRemove][1];
                array_splice($window, self::insertionPoint($window, $value), 1);
                $nextToRemove++;
            }

            $medians[] = $window === [] ? null : self::medianOfSorted($window);
        }

        return $medians;
    }
}
