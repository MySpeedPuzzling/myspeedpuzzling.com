<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A predicted time window as players see it: the stored/computed ends (the calibrated 50 % "likely" window) rounded to
 * a readable grid (docs/features/insights/README.md §3 "Prediction windows", prediction-research.md §12). The one
 * rounding rule of every surface that shows a window - the add-time recap, the puzzle page, the Puzzle Picker, the
 * collections display mode.
 *
 * The step:
 * - 10 s when the prediction is under 3 min (tiny puzzles - a minute grid would show "0-1 min"),
 * - else 1 min when the raw window is under 20 min wide,
 * - else 5 min when the prediction is under 1 h, 10 min from 1 h on.
 * One step for both ends, chosen by the prediction (the centre) like the research measured it.
 *
 * The ends: each end goes to its NEAREST step; an end exactly halfway between two steps goes inward (low up, high
 * down). "Never outward" = the low end is never floored and the high end never ceiled to look generous - rounding both
 * ends outward to 5/10 min turned a 50 % window into a 76 % one (research §12); to the nearest step it stays at 51 %.
 * An end moves by at most half a step, on average as much inward as outward.
 *
 * At least one step wide: a raw window that rounds to nothing (both ends to the same step) becomes the one-step window
 * holding the point prediction (the raw midpoint when none is passed; a prediction outside the raw window counts as
 * its nearer end). The low end is never shown as 0 (a 40 s window low on the 1-minute grid stays
 * 1 min). These two are the only cases an end can land further out than the nearest step.
 *
 * Examples (centre: raw low - raw high → shown), all pinned by PredictionWindowTest:
 * - 1:00:00: 57:20 - 63:40 → 57-64 min (1-minute grid, nearest)
 * - 1:00:00: 56:30 - 63:30 → 57-63 min (both ends halfway: inward)
 * - 1:01:55: 61:40 - 62:10 → 61-62 min (rounds to nothing: the step holding the prediction)
 * - 2:59: 2:56 - 3:04 → 2:50 - 3:00 (10-second grid, rounds to nothing: the step holding 2:59)
 * - 55:00: 44:00 - 1:08:00 → 45-70 min (5-minute grid, the centre is under 1 h)
 * - 1:20:00: 50:00 - 2:20:00 → 50 min - 2 h 20 min (10-minute grid)
 * - 2:00:00: 1:47:00 - 2:16:00 → 1h 50min - 2h 20min (10-minute grid)
 * - 1:30: 1:04 - 1:56 → 1-2 min (10-second grid); 0:45: 0:32 - 0:58 → 30 s - 1 min
 * - 3:20: 0:20 - 6:40 → 1-7 min (the low end is never 0)
 */
final readonly class PredictionWindow
{
    public const int TINY_STEP_SECONDS = 10;
    public const int MINUTE_STEP_SECONDS = 60;
    public const int FIVE_MINUTES_STEP_SECONDS = 300;
    public const int TEN_MINUTES_STEP_SECONDS = 600;

    /** A prediction under this many seconds uses the 10-second grid */
    public const int TINY_PREDICTION_BELOW_SECONDS = 180;

    /** A raw window narrower than this uses the 1-minute grid */
    public const int MINUTE_GRID_WIDTH_BELOW_SECONDS = 1200;

    /** The 5-minute grid below this prediction, the 10-minute grid from it on */
    public const int TEN_MINUTE_GRID_FROM_SECONDS = 3600;

    private function __construct(
        public int $lowSeconds,
        public int $highSeconds,
        public int $stepSeconds,
    ) {
    }

    /**
     * @param int $rawLowSeconds the window's low end as computed/stored
     * @param int $rawHighSeconds the window's high end as computed/stored
     * @param null|int $centreSeconds the point prediction; null = the raw window's midpoint
     */
    public static function rounded(int $rawLowSeconds, int $rawHighSeconds, null|int $centreSeconds = null): self
    {
        $low = max(0, min($rawLowSeconds, $rawHighSeconds));
        $high = max(0, $rawLowSeconds, $rawHighSeconds);
        $centre = $centreSeconds ?? intdiv($low + $high, 2);
        $step = self::stepFor($high - $low, $centre);

        $shownLow = max($step, self::nearestStep($low, $step, roundTiesUp: true));
        $shownHigh = self::nearestStep($high, $step, roundTiesUp: false);

        if ($shownHigh - $shownLow < $step) {
            // Narrower than one step: the one-step window holding the prediction (kept within the raw window, so the
            // step always overlaps it), never starting at 0
            $index = max(1, intdiv(max($low, min($high, $centre)), $step));
            $shownLow = $index * $step;
            $shownHigh = $shownLow + $step;
        }

        return new self($shownLow, $shownHigh, $step);
    }

    public static function stepFor(int $rawWidthSeconds, int $centreSeconds): int
    {
        if ($centreSeconds < self::TINY_PREDICTION_BELOW_SECONDS) {
            return self::TINY_STEP_SECONDS;
        }

        if ($rawWidthSeconds < self::MINUTE_GRID_WIDTH_BELOW_SECONDS) {
            return self::MINUTE_STEP_SECONDS;
        }

        return $centreSeconds < self::TEN_MINUTE_GRID_FROM_SECONDS
            ? self::FIVE_MINUTES_STEP_SECONDS
            : self::TEN_MINUTES_STEP_SECONDS;
    }

    public function contains(int $seconds): bool
    {
        return $seconds >= $this->lowSeconds && $seconds <= $this->highSeconds;
    }

    /**
     * How far a time lies outside the shown window: negative = faster than the low end, positive = slower than the
     * high end, 0 = inside. Measured against the shown ends, so "inside" agrees with what the player reads.
     */
    public function marginOf(int $seconds): int
    {
        if ($seconds < $this->lowSeconds) {
            return $seconds - $this->lowSeconds;
        }

        if ($seconds > $this->highSeconds) {
            return $seconds - $this->highSeconds;
        }

        return 0;
    }

    private static function nearestStep(int $seconds, int $step, bool $roundTiesUp): int
    {
        $below = intdiv($seconds, $step) * $step;
        $twiceRest = 2 * ($seconds - $below);

        if ($twiceRest > $step || ($twiceRest === $step && $roundTiesUp)) {
            return $below + $step;
        }

        return $below;
    }
}
