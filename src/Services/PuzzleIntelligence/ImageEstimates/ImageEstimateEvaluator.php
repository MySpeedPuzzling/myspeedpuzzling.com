<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates;

use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\ImageEstimateEvaluationRow;

/**
 * The numbers of the picture-based estimates' gate record (Insights plan §5.4, docs/features/insights/
 * image-estimate-gates.md) from stored side-by-side predictions - pure, no database.
 *
 * Per size group × image kind, pooled over all (`overall`) and per k bucket (`byK`: 0 / 1-2 / 3-4 indices when the
 * solve was predicted):
 * - puzzle level (one value per puzzle: the estimate of its latest image row; truth = its measured difficulty now,
 *   only from MEASURED_MIN_INDICES indices on): Spearman of difficulty, tier exact / within one next to always
 *   "Average" on the same puzzles;
 * - row level: MAE of ln(actual ÷ predicted) of image, blend and statistical on the rows all three predicted, and the
 *   image predictor's alone; the 50 % / 80 % windows' hit rate and median relative width (high − low) ÷ predicted, of
 *   the image predictor and, for comparison, the blend.
 */
final class ImageEstimateEvaluator
{
    /**
     * A puzzle's difficulty counts as measured from 10 indices on: the research's reliability 0.96 there
     * (prediction-research.md §2 Q3), against noisy labels at 5 that would hide a good model's rank correlation
     */
    public const int MEASURED_MIN_INDICES = 10;

    /** The k range of the gate: picture-only estimates are for puzzles nobody - or hardly anybody - has solved yet */
    public const int MAX_K = 4;

    private const int DECIMALS = 4;

    /**
     * @param iterable<ImageEstimateEvaluationRow> $rows
     * @return array{groups: list<array<string, mixed>>, overall: array<string, mixed>, byK: list<array<string, mixed>>}
     */
    public static function evaluate(iterable $rows, int $measuredMinIndices = self::MEASURED_MIN_INDICES): array
    {
        /** @var array<string, RowAccumulator> $groups */
        $groups = [];
        /** @var array<string, RowAccumulator> $byK */
        $byK = [];
        $overall = new RowAccumulator();

        foreach ($rows as $row) {
            $groupKey = $row->sizeGroup->value . '|' . $row->imageKind->value;
            $kKey = self::kBucket($row->kIndices);

            ($groups[$groupKey] ??= new RowAccumulator())->add($row, $measuredMinIndices);
            ($byK[$kKey] ??= new RowAccumulator())->add($row, $measuredMinIndices);
            $overall->add($row, $measuredMinIndices);
        }

        ksort($groups);
        $groupMetrics = [];

        foreach ($groups as $key => $accumulator) {
            [$sizeGroup, $imageKind] = explode('|', $key);
            $groupMetrics[] = ['sizeGroup' => $sizeGroup, 'imageKind' => $imageKind] + self::metrics($accumulator);
        }

        $kMetrics = [];

        foreach (['0', '1-2', '3-4'] as $kKey) {
            if (isset($byK[$kKey])) {
                $kMetrics[] = ['k' => $kKey] + self::metrics($byK[$kKey]);
            }
        }

        return ['groups' => $groupMetrics, 'overall' => self::metrics($overall), 'byK' => $kMetrics];
    }

    /**
     * Spearman's rank correlation (ties share their mean rank); null below 3 pairs or without variation.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function spearman(array $a, array $b): null|float
    {
        $n = count($a);

        if ($n < 3 || $n !== count($b)) {
            return null;
        }

        $rankA = self::ranks($a);
        $rankB = self::ranks($b);
        $meanA = array_sum($rankA) / $n;
        $meanB = array_sum($rankB) / $n;
        $covariance = 0.0;
        $varianceA = 0.0;
        $varianceB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $da = $rankA[$i] - $meanA;
            $db = $rankB[$i] - $meanB;
            $covariance += $da * $db;
            $varianceA += $da * $da;
            $varianceB += $db * $db;
        }

        if ($varianceA <= 0.0 || $varianceB <= 0.0) {
            return null;
        }

        return $covariance / sqrt($varianceA * $varianceB);
    }

    /**
     * @return array<string, null|int|float>
     */
    private static function metrics(RowAccumulator $accumulator): array
    {
        $estimates = [];
        $measured = [];
        $exact = 0;
        $withinOne = 0;
        $average = 0;

        foreach ($accumulator->puzzles as [$estimate, , $truth]) {
            if ($truth === null) {
                continue;
            }

            $estimates[] = $estimate;
            $measured[] = $truth;
            $estimatedTier = DifficultyTier::fromScore(exp($estimate))->value;
            $measuredTier = DifficultyTier::fromScore(exp($truth))->value;
            $exact += $estimatedTier === $measuredTier ? 1 : 0;
            $withinOne += abs($estimatedTier - $measuredTier) <= 1 ? 1 : 0;
            $average += $measuredTier === DifficultyTier::Average->value ? 1 : 0;
        }

        $measuredPuzzles = count($measured);

        return [
            'rows' => $accumulator->rows,
            'puzzles' => count($accumulator->puzzles),
            'measuredPuzzles' => $measuredPuzzles,
            'spearman' => self::round(self::spearman($estimates, $measured)),
            'tierExact' => self::share($exact, $measuredPuzzles),
            'tierWithinOne' => self::share($withinOne, $measuredPuzzles),
            'alwaysAverage' => self::share($average, $measuredPuzzles),
            'pairedRows' => $accumulator->pairedRows,
            'maeImage' => self::mean($accumulator->pairedImageError, $accumulator->pairedRows),
            'maeBlend' => self::mean($accumulator->pairedBlendError, $accumulator->pairedRows),
            'maeStatistical' => self::mean($accumulator->pairedStatisticalError, $accumulator->pairedRows),
            'maeImageAll' => self::mean($accumulator->imageError, $accumulator->rows),
            'window50Hit' => self::share($accumulator->imageHit50, $accumulator->rows),
            'window50Width' => self::round(self::median($accumulator->imageWidth50)),
            'window80Hit' => self::share($accumulator->imageHit80, $accumulator->rows),
            'window80Width' => self::round(self::median($accumulator->imageWidth80)),
            'blendRows' => $accumulator->blendRows,
            'blendWindow50Hit' => self::share($accumulator->blendHit50, $accumulator->blendRows),
            'blendWindow50Width' => self::round(self::median($accumulator->blendWidth50)),
            'blendWindow80Hit' => self::share($accumulator->blendHit80, $accumulator->blendRows),
            'blendWindow80Width' => self::round(self::median($accumulator->blendWidth80)),
        ];
    }

    private static function kBucket(int $k): string
    {
        return match (true) {
            $k <= 0 => '0',
            $k <= 2 => '1-2',
            default => '3-4',
        };
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private static function ranks(array $values): array
    {
        $order = array_keys($values);
        usort($order, static fn (int $x, int $y): int => $values[$x] <=> $values[$y]);

        $ranks = array_fill(0, count($values), 0.0);
        $count = count($order);
        $i = 0;

        while ($i < $count) {
            $j = $i;

            while ($j + 1 < $count && $values[$order[$j + 1]] == $values[$order[$i]]) {
                $j++;
            }

            $rank = ($i + $j) / 2.0 + 1.0;

            for ($m = $i; $m <= $j; $m++) {
                $ranks[$order[$m]] = $rank;
            }

            $i = $j + 1;
        }

        // array_fill() made the keys 0..n-1 in order
        return array_values($ranks);
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): null|float
    {
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        sort($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function share(int $count, int $of): null|float
    {
        return $of > 0 ? round($count / $of, self::DECIMALS) : null;
    }

    private static function mean(float $sum, int $of): null|float
    {
        return $of > 0 ? round($sum / $of, self::DECIMALS) : null;
    }

    private static function round(null|float $value): null|float
    {
        return $value !== null ? round($value, self::DECIMALS) : null;
    }
}
