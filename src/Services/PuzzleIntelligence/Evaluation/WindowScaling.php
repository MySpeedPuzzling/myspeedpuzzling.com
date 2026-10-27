<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

/**
 * Re-fitting the width of stored windows without re-running the model (docs/features/insights/image-estimate-gates.md,
 * "Honesty"). Every window of the time model is centre × exp(q × sigma) (TimePredictionCalculator::window()), and the
 * fitted scales (the image variance scale, the quantile tables) only stretch q × sigma - so a window scaled by s is
 * [ln C + s × (ln L − ln C), ln C + s × (ln H − ln C)] in log space. For one solve the scales s whose window holds the
 * actual time form an interval (scaleInterval()); the scale a set of solves asks for is the smallest one holding the
 * target share of them (fitScale()) - what a fit on those solves alone would have stored.
 *
 * At s = 1 the hit is the stored window's own hit. Pure, no database.
 */
final class WindowScaling
{
    /**
     * The scales s ≥ 0 at which the window around $centre holds $actual: [from, to] (to = INF when every wider window
     * holds it too - a window whose centre lies inside it), null when no scale does (a window off its centre, the
     * actual time on the other side).
     *
     * @return null|array{0: float, 1: float}
     */
    public static function scaleInterval(int $actual, int $centre, int $low, int $high): null|array
    {
        $logCentre = log(max(1, $centre));
        $y = log(max(1, $actual)) - $logCentre;
        $toLow = log(max(1, $low)) - $logCentre;
        $toHigh = log(max(1, $high)) - $logCentre;
        $from = 0.0;
        $to = INF;

        // s × toLow ≤ y
        if ($toLow < 0.0) {
            $from = max($from, $y / $toLow);
        } elseif ($toLow > 0.0) {
            $to = min($to, $y / $toLow);
        } elseif ($y < 0.0) {
            return null;
        }

        // s × toHigh ≥ y
        if ($toHigh > 0.0) {
            $from = max($from, $y / $toHigh);
        } elseif ($toHigh < 0.0) {
            $to = min($to, $y / $toHigh);
        } elseif ($y > 0.0) {
            return null;
        }

        return $from <= $to ? [$from, $to] : null;
    }

    /**
     * The smallest scale whose windows hold at least $target of the solves (null intervals never held); null when no
     * scale reaches it.
     *
     * @param list<float> $from scaleInterval()[0] of every solve held at some scale
     * @param list<float> $to scaleInterval()[1] of the same solves
     * @param int $count every solve, also those no scale holds
     */
    public static function fitScale(array $from, array $to, int $count, float $target): null|float
    {
        if ($count === 0) {
            return null;
        }

        sort($from);
        sort($to);
        $needed = $target * $count - 1e-9;
        $ended = 0;
        $starts = count($from);
        $ends = count($to);

        // Coverage only grows at an interval's start: the smallest scale reaching the target is one of them
        for ($i = 0; $i < $starts; $i++) {
            $scale = $from[$i];

            if ($i + 1 < $starts && $from[$i + 1] <= $scale) {
                continue;
            }

            while ($ended < $ends && $to[$ended] < $scale) {
                $ended++;
            }

            if ($i + 1 - $ended >= $needed) {
                return $scale;
            }
        }

        return null;
    }

    /**
     * How many of the solves the windows scaled by $scale hold
     *
     * @param list<float> $from
     * @param list<float> $to
     */
    public static function hitsAt(array $from, array $to, float $scale): int
    {
        $hits = 0;

        foreach ($from as $i => $start) {
            $hits += $start <= $scale && $scale <= $to[$i] ? 1 : 0;
        }

        return $hits;
    }
}
