<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The point time of a personal estimate as players read it (docs/features/insights/estimates.md, README §3
 * "Estimates"): rounded to what people can use - 5 min under an hour, 10 min from an hour on, the minute under 10 min
 * (5-minute steps would turn a 7-minute 300 into "5 min"), never 0. The window ends follow PredictionWindow (Phase 3c).
 *
 * The rounded time never contradicts the window shown next to it: when the 5 / 10-minute step would put it outside
 * the shown 50 % window (a narrow window on the minute grid), it rounds on the window's own step instead, and lands
 * inside the window at worst on its nearer end.
 *
 * Examples (pinned by EstimateTimeTest):
 * - 58:40 → 1 h (5-minute step); 3:04:00 → 3 h; 3:06:00 → 3 h 10 min; 7:20 → 7 min
 * - 2:04:00 with the window 2:01-2:06 (minute grid) → 2:04 (the 10-minute step would say 2 h, outside it)
 */
final readonly class EstimateTime
{
    public const int MINUTE_STEP_BELOW_SECONDS = 600;
    public const int FIVE_MINUTE_STEP_BELOW_SECONDS = 3600;

    public static function rounded(int $seconds, null|PredictionWindow $shownWindow = null): int
    {
        $step = self::stepFor($seconds);
        $rounded = self::nearest($seconds, $step);

        if ($shownWindow === null || $shownWindow->contains($rounded)) {
            return $rounded;
        }

        $finer = self::nearest($seconds, min($step, $shownWindow->stepSeconds));

        return max($shownWindow->lowSeconds, min($shownWindow->highSeconds, $finer));
    }

    public static function stepFor(int $seconds): int
    {
        return match (true) {
            $seconds < self::MINUTE_STEP_BELOW_SECONDS => 60,
            $seconds < self::FIVE_MINUTE_STEP_BELOW_SECONDS => 300,
            default => 600,
        };
    }

    private static function nearest(int $seconds, int $step): int
    {
        return max($step, (int) round(max(0, $seconds) / $step) * $step);
    }
}
