<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The viewer's position line above a board: "#1 289 of 7 878 in Czechia · top 17 % · 46 XP to #1 000"
 * (docs/features/puzzle-leaderboard-chart.md "Position line", docs/features/xp-levels/ux-polish/design-spec.md §4).
 *
 * INTERFACE (stable - shared by the puzzle page and every board):
 *   LeaderboardPosition::describe(
 *       rank: 1289,                      // shared rank (RANK(): equal values share the place)
 *       total: 7878,                     // rows of the list on screen (its filters applied)
 *       ownValue: 185,                   // the viewer's ranked value (XP, AP, karma, rating, seconds)
 *       milestoneValues: [1000 => 231],  // the value at 1-based list position N for N of RANK_MILESTONES (missing/null = none)
 *       higherIsBetter: true,            // XP/AP/karma/rating true, times false
 *       rowsBehind: null,                // rows strictly worse than the viewer - only for the percentile ("faster than N %")
 *   )
 *   ->rank, ->total, ->topPercent (null = not shown), ->percentile (null = not shown), ->targetRank + ->targetGap (null = none)
 *   ->topFraction: the same share as a fraction for the locale's percent format (design-spec §8.5) - rounded up to a
 *   whole percent, to one decimal under 10 % (templates/leaderboards/_position_line.html.twig formats it)
 *   LeaderboardPosition::RANK_MILESTONES - the positions a statement loads for the target
 */
final readonly class LeaderboardPosition
{
    /**
     * The position line names the gap to the nearest of these ranks above the viewer ("… to #500")
     */
    public const array RANK_MILESTONES = [1, 3, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    // Below this many rows a percentage says little ("faster than 50 %" of three people)
    public const int PERCENTILE_MIN_ROWS = 10;

    // "top 64 %" says "most are ahead of you" - only the upper half is told as a share (no loss framing, P10)
    public const int TOP_PERCENT_MAX = 50;

    public function __construct(
        public int $rank,
        public int $total,
        // Share of the other rows strictly behind the viewer, rounded down (the puzzle page's "faster than N %")
        public null|int $percentile = null,
        // The viewer's rank as a share of the list, rounded up ("top 17 %")
        public null|int $topPercent = null,
        // The nearest milestone rank above the viewer and the value still missing to reach it
        public null|int $targetRank = null,
        public null|int $targetGap = null,
        // topPercent as a fraction (0.17), one decimal under 10 % (0.032)
        public null|float $topFraction = null,
    ) {
    }

    /**
     * @param array<int, null|int> $milestoneValues 1-based list position => the value of the row there
     */
    public static function describe(
        int $rank,
        int $total,
        null|int $ownValue,
        array $milestoneValues,
        bool $higherIsBetter,
        null|int $rowsBehind = null,
    ): self {
        if ($rank === 1 || $ownValue === null) {
            return new self($rank, $total);
        }

        $percentile = null;
        $topPercent = null;
        $topFraction = null;

        if ($total >= self::PERCENTILE_MIN_ROWS) {
            if ($rowsBehind !== null) {
                $share = intdiv(100 * $rowsBehind, $total - 1);
                $percentile = $share > 0 ? $share : null;
            }

            $top = (int) ceil(100 * $rank / $total);
            $topPercent = $top <= self::TOP_PERCENT_MAX ? max(1, $top) : null;

            if ($topPercent !== null) {
                $perMille = (int) ceil(1000 * $rank / $total);
                $topFraction = $perMille < 100 ? $perMille / 1000 : $topPercent / 100;
            }
        }

        foreach (array_reverse(self::RANK_MILESTONES) as $milestone) {
            $milestoneValue = $milestoneValues[$milestone] ?? null;

            if ($milestone < $rank && $milestoneValue !== null) {
                return new self(
                    rank: $rank,
                    total: $total,
                    percentile: $percentile,
                    topPercent: $topPercent,
                    targetRank: $milestone,
                    targetGap: $higherIsBetter ? $milestoneValue - $ownValue : $ownValue - $milestoneValue,
                    topFraction: $topFraction,
                );
            }
        }

        return new self($rank, $total, $percentile, $topPercent, topFraction: $topFraction);
    }
}
