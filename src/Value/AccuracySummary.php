<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A distribution in a few numbers (the online accuracy report, docs/features/online-competitions/README.md §5.9):
 * how many values, the median, the quartiles and their range (IQR). Quartiles by linear interpolation between the
 * closest ranks (the common "type 7", numpy's and spreadsheets' default) - assets/online/review_logic.js summarize()
 * computes the same numbers on the review page, pinned by the same cases in AccuracySummaryTest and
 * tests/online-review-harness.mjs.
 */
final readonly class AccuracySummary
{
    private function __construct(
        public int $count,
        public null|float $median,
        public null|float $q1,
        public null|float $q3,
        public null|float $iqr,
    ) {
    }

    /**
     * @param list<int|float> $values
     */
    public static function of(array $values): self
    {
        if ($values === []) {
            return new self(0, null, null, null, null);
        }

        sort($values);
        $q1 = self::quantile($values, 0.25);
        $q3 = self::quantile($values, 0.75);

        return new self(
            count: count($values),
            median: self::quantile($values, 0.5),
            q1: $q1,
            q3: $q3,
            iqr: $q3 - $q1,
        );
    }

    /**
     * @param non-empty-list<int|float> $sorted
     */
    private static function quantile(array $sorted, float $p): float
    {
        $position = (count($sorted) - 1) * $p;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return (float) $sorted[$lower] + ($position - $lower) * ((float) $sorted[$upper] - (float) $sorted[$lower]);
    }
}
