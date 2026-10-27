<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Value\StoredPredictionWindows;

/**
 * Honesty of the windows out of period (docs/features/insights/image-estimate-gates.md, "Honesty"): the window scales
 * are fitted on the solves before a moment only (WindowScaling::fitScale()), and the hits are counted on later solves
 * with those scales - what the stored windows would have held had their fit known nothing of the later solves (the
 * production fit of the image scale and the quantile tables sees the whole period, so the stored windows' own hit rate
 * is in-sample). The solves from the split moment T on are tested in 30-day blocks, each fitted on everything before
 * it (a rolling origin, metrics()). The verdict (failures()) asks the later hits' Wilson interval to lie within
 * ± TOLERANCE of 50 % / 80 %, and keeps a group that passed open until its share leaves ± CLOSE_TOLERANCE (hysteresis:
 * a daily re-run does not flap on noise).
 *
 * The accumulator keeps five floats per solve.
 */
final class OutOfPeriodCalibration
{
    public const float TOLERANCE = 0.03;
    public const float CLOSE_TOLERANCE = 0.05;

    /** The test period is refitted every this many days (a rolling origin) */
    public const int BLOCK_DAYS = 30;

    private const array TARGETS = [50 => 0.50, 80 => 0.80];
    private const int DECIMALS = 4;

    /** @var list<float> */
    private array $solvedAt = [];

    /** @var array<int, list<float>> per level: from of the scale interval (INF = never held) */
    private array $from = [50 => [], 80 => []];

    /** @var array<int, list<float>> per level: to of the scale interval (-INF = never held) */
    private array $to = [50 => [], 80 => []];

    public function add(float $solvedAt, int $actualSeconds, StoredPredictionWindows $windows): void
    {
        $this->solvedAt[] = $solvedAt;

        foreach ([50, 80] as $level) {
            [$low, $high] = $windows->ends($level);
            $interval = WindowScaling::scaleInterval($actualSeconds, $windows->predictedSeconds, $low, $high);
            $this->from[$level][] = $interval[0] ?? INF;
            $this->to[$level][] = $interval[1] ?? -INF;
        }
    }

    public function count(): int
    {
        return count($this->solvedAt);
    }

    /**
     * The solve moment splitting the solves in two halves - the record's T (one for all groups)
     *
     * @param list<float> $solvedAt
     */
    public static function medianMoment(array $solvedAt): null|float
    {
        if ($solvedAt === []) {
            return null;
        }

        sort($solvedAt);

        return $solvedAt[intdiv(count($solvedAt), 2)];
    }

    /**
     * @return list<float>
     */
    public function solvedAt(): array
    {
        return $this->solvedAt;
    }

    /**
     * The solves from $splitAt on are tested in blocks of BLOCK_DAYS, each with the scales fitted on every solve before
     * the block (the production fit is refitted daily on the trailing year - a single fit tested half a year ahead
     * would overstate the drift a member meets). The hits of all blocks are pooled; a block whose fit has no solve, or
     * reaches no scale, counts as solves never held.
     *
     * @return array{fitRows: int, testRows: int, blocks: int, scale50: null|float, scale80: null|float, hit50: null|float, hit50Low: null|float, hit50High: null|float, hit80: null|float, hit80Low: null|float, hit80High: null|float, inSample50: null|float, inSample80: null|float}
     */
    public function metrics(float $splitAt): array
    {
        $order = array_keys($this->solvedAt);
        usort($order, fn (int $a, int $b): int => $this->solvedAt[$a] <=> $this->solvedAt[$b]);
        $all = count($order);
        $firstTest = 0;

        while ($firstTest < $all && $this->solvedAt[$order[$firstTest]] < $splitAt) {
            $firstTest++;
        }

        $metrics = ['fitRows' => $firstTest, 'testRows' => $all - $firstTest, 'blocks' => 0];

        foreach ([50, 80] as $level) {
            $from = [];
            $to = [];
            $inSample = 0;

            foreach ($order as $i) {
                $from[] = $this->from[$level][$i];
                $to[] = $this->to[$level][$i];
                $inSample += $this->from[$level][$i] <= 1.0 && 1.0 <= $this->to[$level][$i] ? 1 : 0;
            }

            $hits = 0;
            $blocks = 0;
            $scale = null;
            $position = $firstTest;

            while ($position < $all) {
                // Blocks are anchored at the split moment: the same calendar for every group
                $block = self::BLOCK_DAYS * 86400;
                $blockEnd = $splitAt + (floor(($this->solvedAt[$order[$position]] - $splitAt) / $block) + 1) * $block;
                $end = $position;

                while ($end < $all && $this->solvedAt[$order[$end]] < $blockEnd) {
                    $end++;
                }

                $fitFrom = [];
                $fitTo = [];

                for ($j = 0; $j < $position; $j++) {
                    if ($from[$j] <= $to[$j]) {
                        $fitFrom[] = $from[$j];
                        $fitTo[] = $to[$j];
                    }
                }

                $scale = WindowScaling::fitScale($fitFrom, $fitTo, $position, self::TARGETS[$level]);

                if ($scale !== null) {
                    $hits += WindowScaling::hitsAt(array_slice($from, $position, $end - $position), array_slice($to, $position, $end - $position), $scale);
                }

                $blocks++;
                $position = $end;
            }

            $testCount = $all - $firstTest;
            $bounds = Wilson::bounds($hits, $testCount);

            $metrics['blocks'] = $blocks;
            // The scale of the last block's fit - every solve before it
            $metrics['scale' . $level] = self::round($scale);
            $metrics['hit' . $level] = $testCount > 0 ? round($hits / $testCount, self::DECIMALS) : null;
            $metrics['hit' . $level . 'Low'] = $bounds !== null ? round($bounds[0], self::DECIMALS) : null;
            $metrics['hit' . $level . 'High'] = $bounds !== null ? round($bounds[1], self::DECIMALS) : null;
            $metrics['inSample' . $level] = $all > 0 ? round($inSample / $all, self::DECIMALS) : null;
        }

        /** @var array{fitRows: int, testRows: int, blocks: int, scale50: null|float, scale80: null|float, hit50: null|float, hit50Low: null|float, hit50High: null|float, hit80: null|float, hit80Low: null|float, hit80High: null|float, inSample50: null|float, inSample80: null|float} $metrics */
        return $metrics;
    }

    /**
     * The verdict on stored numbers (metrics()): open when, for both windows, the later solves' Wilson interval lies
     * within target ± TOLERANCE - or, for a group open in the previous record, while the share itself stays within
     * target ± CLOSE_TOLERANCE. A missing number = closed.
     *
     * @param array<string, mixed> $calibration
     * @return list<string> why it is closed (empty = open)
     */
    public static function failures(array $calibration, bool $previouslyOpen): array
    {
        $failures = [];

        foreach (self::TARGETS as $level => $target) {
            $hit = self::number($calibration['hit' . $level] ?? null);
            $low = self::number($calibration['hit' . $level . 'Low'] ?? null);
            $high = self::number($calibration['hit' . $level . 'High'] ?? null);

            if ($hit === null || $low === null || $high === null) {
                $failures[] = sprintf('no out-of-period %d %% window numbers', $level);

                continue;
            }

            if ($previouslyOpen) {
                if (abs($hit - $target) > self::CLOSE_TOLERANCE + 1e-9) {
                    $failures[] = sprintf('%d %% window held %.3f after the split, beyond %.2f ± %.2f (open before - hysteresis)', $level, $hit, $target, self::CLOSE_TOLERANCE);
                }

                continue;
            }

            if ($low < $target - self::TOLERANCE - 1e-9 || $high > $target + self::TOLERANCE + 1e-9) {
                $failures[] = sprintf('%d %% window held %.3f after the split, Wilson %.3f-%.3f not within %.2f ± %.2f', $level, $hit, $low, $high, $target, self::TOLERANCE);
            }
        }

        return $failures;
    }

    private static function number(mixed $value): null|float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function round(null|float $value): null|float
    {
        return $value !== null ? round($value, self::DECIMALS) : null;
    }
}
