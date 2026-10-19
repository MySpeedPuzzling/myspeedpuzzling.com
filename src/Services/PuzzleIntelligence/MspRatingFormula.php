<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

/**
 * The MSP rating's arithmetic, pure: the batch (MspRatingCalculator) and every what-if built on the stored rating
 * entries and time arrays (docs/features/insights/computation.md) use these very functions, so a what-if that changes
 * nothing reproduces the stored points exactly.
 */
final class MspRatingFormula
{
    /** Entries per portfolio that count */
    public const int CAP = 100;

    public const int WINDOW_MONTHS = 24;

    /** A puzzle counts once this many public players have a first attempt on it (and it has a difficulty) */
    public const int MIN_SOLVERS_PER_PUZZLE = 20;

    public const float FIRST_ATTEMPT_WEIGHT = 0.75;

    private const float DECAY_PLATEAU_MONTHS = 3.0;
    private const float DECAY_RATE_MONTHS = 30.0;
    private const float DIFFICULTY_BLEND = 0.5;
    private const int DIFF_CONFIDENCE_THRESHOLD = 50;

    /**
     * Percentile of a time among the puzzle's times, average rank: 0.0 (slowest) to 1.0 (fastest). The time itself is
     * one of the times (the player's own entry), so one tied time is the player and is left out. Binary search: the same
     * counts, so the same value, as the linear scan it replaced.
     *
     * @param list<int> $sortedTimes ascending
     */
    public static function percentileInSortedTimes(int $time, array $sortedTimes): float
    {
        $total = count($sortedTimes);

        if ($total <= 1) {
            return 0.5;
        }

        $firstNotFaster = self::lowerBound($sortedTimes, $time);
        $firstSlower = self::upperBound($sortedTimes, $time);
        $slowerCount = $total - $firstSlower;
        $tiedOthers = max(0, $firstSlower - $firstNotFaster - 1);

        return ($slowerCount + $tiedOthers / 2.0) / ($total - 1);
    }

    /**
     * Index of the first time >= $time
     *
     * @param list<int> $sortedTimes ascending
     */
    public static function lowerBound(array $sortedTimes, int $time): int
    {
        $low = 0;
        $high = count($sortedTimes);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sortedTimes[$middle] < $time) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * Index of the first time > $time
     *
     * @param list<int> $sortedTimes ascending
     */
    public static function upperBound(array $sortedTimes, int $time): int
    {
        $low = 0;
        $high = count($sortedTimes);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sortedTimes[$middle] <= $time) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    public static function difficultyWeight(float $difficulty, int $sampleSize): float
    {
        $confidence = min(1.0, $sampleSize / self::DIFF_CONFIDENCE_THRESHOLD);
        $blend = self::DIFFICULTY_BLEND * $confidence;

        return (1.0 - $blend) + $blend * $difficulty;
    }

    /**
     * 1.0 for the first 3 months, then exp(-(months - 3) / 30). Months are whole months plus days / 30 of the
     * calendar difference, so a value moves once a day, at the time of day of the solve.
     */
    public static function decay(\DateTimeImmutable $solvedAt, \DateTimeImmutable $now): float
    {
        $diff = $solvedAt->diff($now);
        $ageInMonths = ($diff->y * 12) + $diff->m + ($diff->d / 30.0);
        $effectiveAge = max(0.0, $ageInMonths - self::DECAY_PLATEAU_MONTHS);

        return exp(-$effectiveAge / self::DECAY_RATE_MONTHS);
    }

    /**
     * @param list<float> $firstAttemptTopPoints the counted first-attempt entries (at most CAP), best first
     * @param list<float> $bestTimeTopPoints the counted best-time entries (at most CAP), best first
     */
    public static function rating(array $firstAttemptTopPoints, array $bestTimeTopPoints): float
    {
        if ($firstAttemptTopPoints === []) {
            return 0.0;
        }

        $faScore = array_sum($firstAttemptTopPoints) / count($firstAttemptTopPoints);
        $btScore = $bestTimeTopPoints !== [] ? array_sum($bestTimeTopPoints) / count($bestTimeTopPoints) : 0.0;

        return self::FIRST_ATTEMPT_WEIGHT * $faScore + (1.0 - self::FIRST_ATTEMPT_WEIGHT) * $btScore;
    }

    public static function windowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . self::WINDOW_MONTHS . ' months');
    }
}
