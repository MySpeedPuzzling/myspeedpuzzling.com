<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * Percentiles of a time that is not in the stored lists yet, by binary search over puzzle_rating_times
 * (docs/features/insights/computation.md §4) - the same counts MspRatingFormula::percentileInSortedTimes() takes, so a
 * what-if that changes nothing gives the stored percentile back.
 */
final class RatingWhatIf
{
    /**
     * The percentile of $newTime when it replaces the player's own $ownTime in the list (a re-solve). When $ownTime is
     * not in the list (the player's result came after the last batch run, or the player is private), $newTime is
     * inserted instead.
     *
     * @param list<int> $sortedTimes ascending
     */
    public static function percentileReplacing(array $sortedTimes, int $ownTime, int $newTime): float
    {
        $ownIndex = MspRatingFormula::lowerBound($sortedTimes, $ownTime);

        if (($sortedTimes[$ownIndex] ?? null) !== $ownTime) {
            return self::percentileInserting($sortedTimes, $newTime);
        }

        $total = count($sortedTimes);

        if ($total <= 1) {
            return 0.5;
        }

        // The others are the list without the own time; the new time joins them
        $slower = $total - MspRatingFormula::upperBound($sortedTimes, $newTime) - ($ownTime > $newTime ? 1 : 0);
        $tied = MspRatingFormula::upperBound($sortedTimes, $newTime) - MspRatingFormula::lowerBound($sortedTimes, $newTime) - ($ownTime === $newTime ? 1 : 0);

        return ($slower + $tied / 2.0) / ($total - 1);
    }

    /**
     * The percentile of $newTime added to the list as one more solver (a first try).
     *
     * @param list<int> $sortedTimes ascending
     */
    public static function percentileInserting(array $sortedTimes, int $newTime): float
    {
        $others = count($sortedTimes);

        if ($others === 0) {
            return 0.5;
        }

        $slower = $others - MspRatingFormula::upperBound($sortedTimes, $newTime);
        $tied = MspRatingFormula::upperBound($sortedTimes, $newTime) - MspRatingFormula::lowerBound($sortedTimes, $newTime);

        return ($slower + $tied / 2.0) / $others;
    }
}
