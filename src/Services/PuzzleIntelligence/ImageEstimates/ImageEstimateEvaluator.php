<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\OutOfPeriodCalibration;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionGroupAccumulator;
use SpeedPuzzling\Web\Value\StoredPredictionWindows;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\TierScores;
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
 *   "Average" on the same puzzles - a puzzle counts in the size group × kind (and k bucket) of its latest row only, never
 *   in two kinds when its picture was reclassified;
 * - row level: MAE of ln(actual ÷ predicted) of image, blend and statistical on the rows all three predicted, and the
 *   image predictor's alone; the 50 % / 80 % windows' hit rate and median relative width (high − low) ÷ predicted, of
 *   the image predictor and, for comparison, the blend; how many rows each estimate generation ("<model> <version>")
 *   made (`estimateModels`).
 *
 * Gate v2 (the record's `gateVersion` 2 - image-estimate-gates.md "Gate v2") adds, per group and pooled:
 * - `calibration`: the picture-only windows' honesty out of period (OutOfPeriodCalibration: scales fitted on the solves
 *   before the record's split moment, hits counted after it, Wilson bounds) and `previouslyOpen` - whether the
 *   previous record's numbers opened it for the same estimate generations (the hysteresis, judged on read);
 * - `skill` per k bucket (PairedSkill): at k = 0 the picture-only prediction against the statistical (brand × pieces
 *   prior) one with the 80 % window width at equal coverage, at k = 1-4 the blend against the statistical;
 * - `slices`: `k0` (every newer puzzle) and `k0Measured` (only those measured from MEASURED_MIN_INDICES on - the
 *   population the puzzle-level numbers see), `live` and `reconstructed`, each with the picture-only windows' hits and
 *   the paired errors;
 * - the tier numbers TierScores reports (never gating): the exact tier's standard error and label-noise ceiling, the
 *   tier range's coverage, the ranked probability skill score.
 */
final class ImageEstimateEvaluator
{
    /** The gate rules this evaluator's records are for (ImageEstimateGate) - 1 = the first rules, numbers without the v2 keys */
    public const int GATE_VERSION = 2;

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
     * @param array<string, list<string>> $openBefore the groups ("<size group>|<image kind>") whose honesty the previous
     *                                                record's numbers opened, with the estimate generations it measured
     *                                                there (sorted) - a group stays open by hysteresis for the same ones only
     * @param null|float $splitAt the out-of-period split moment (epoch seconds); null = the median solve moment
     * @return array{groups: list<array<string, mixed>>, overall: array<string, mixed>, byK: list<array<string, mixed>>, splitAt: null|float}
     */
    public static function evaluate(iterable $rows, int $measuredMinIndices = self::MEASURED_MIN_INDICES, array $openBefore = [], null|float $splitAt = null): array
    {
        /** @var array<string, RowAccumulator> $groups */
        $groups = [];
        /** @var array<string, PredictionGroupAccumulator> $groupsV2 */
        $groupsV2 = [];
        /** @var array<string, RowAccumulator> $byK */
        $byK = [];
        $overall = new RowAccumulator();
        $overallV2 = new PredictionGroupAccumulator();
        // Per puzzle, from its latest row: [estimate (log), that row's time, measured log difficulty or null, group,
        // k bucket, measured indices, the estimate interval's p10 and p90 offsets]
        /** @var array<string, array{0: float, 1: float, 2: null|float, 3: string, 4: string, 5: int, 6: null|float, 7: null|float}> $puzzles */
        $puzzles = [];

        foreach ($rows as $row) {
            $groupKey = $row->sizeGroup->value . '|' . $row->imageKind->value;
            $kKey = self::kBucket($row->kIndices);

            ($groups[$groupKey] ??= new RowAccumulator())->add($row);
            ($byK[$kKey] ??= new RowAccumulator())->add($row);
            $overall->add($row);

            $image = new StoredPredictionWindows($row->imageSeconds, $row->imageRange80Low, $row->imageRange50Low, $row->imageRange50High, $row->imageRange80High);
            // At k = 0 the blend is the picture alone (the same row); from 1 index on, what would be shown with the image prior
            $candidate = $row->kIndices <= 0
                ? $image
                : StoredPredictionWindows::tryOf($row->blendSeconds, $row->blendRange80Low, $row->blendRange50Low, $row->blendRange50High, $row->blendRange80High);
            $reference = StoredPredictionWindows::tryOf($row->statisticalSeconds, $row->statisticalRange80Low, $row->statisticalRange50Low, $row->statisticalRange50High, $row->statisticalRange80High);
            $slices = [$row->live ? 'live' : 'reconstructed'];

            if ($row->kIndices <= 0) {
                $slices[] = 'k0';

                if ($row->measuredIndices >= $measuredMinIndices) {
                    $slices[] = 'k0Measured';
                }
            }

            ($groupsV2[$groupKey] ??= new PredictionGroupAccumulator())->add($row->kIndices, $row->solvedAt, $row->actualSeconds, $image, $candidate, $reference, $slices);
            $overallV2->add($row->kIndices, $row->solvedAt, $row->actualSeconds, $image, $candidate, $reference, $slices);

            $known = $puzzles[$row->puzzleId] ?? null;

            if ($known === null || $row->computedAt > $known[1]) {
                $measured = $row->measuredIndices >= $measuredMinIndices && $row->measuredScore !== null && $row->measuredScore > 0.0
                    ? log($row->measuredScore)
                    : null;
                $puzzles[$row->puzzleId] = [$row->imageLogDifficulty, $row->computedAt, $measured, $groupKey, $kKey, $row->measuredIndices, $row->estimateP10Offset, $row->estimateP90Offset];
            }
        }

        $splitAt ??= OutOfPeriodCalibration::medianMoment($overallV2->calibration->solvedAt());

        ksort($groups);
        $groupMetrics = [];

        foreach ($groups as $key => $accumulator) {
            [$sizeGroup, $imageKind] = explode('|', $key);
            $own = array_filter($puzzles, static fn (array $puzzle): bool => $puzzle[3] === $key);
            $metrics = ['sizeGroup' => $sizeGroup, 'imageKind' => $imageKind] + self::metrics($accumulator, $own) + $groupsV2[$key]->metrics($splitAt);
            $generations = array_map('strval', array_keys($accumulator->estimateModels));
            sort($generations);
            $metrics['calibration']['previouslyOpen'] = isset($openBefore[$key]) && $openBefore[$key] === $generations;
            $groupMetrics[] = $metrics;
        }

        $kMetrics = [];

        foreach (['0', '1-2', '3-4'] as $kKey) {
            if (isset($byK[$kKey])) {
                $own = array_filter($puzzles, static fn (array $puzzle): bool => $puzzle[4] === $kKey);
                $kMetrics[] = ['k' => $kKey] + self::metrics($byK[$kKey], $own);
            }
        }

        return [
            'groups' => $groupMetrics,
            'overall' => self::metrics($overall, $puzzles) + $overallV2->metrics($splitAt),
            'byK' => $kMetrics,
            'splitAt' => $splitAt,
        ];
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
     * @param array<string, array{0: float, 1: float, 2: null|float, 3: string, 4: string, 5: int, 6: null|float, 7: null|float}> $puzzles the group's own (by their latest row)
     * @return array<string, mixed>
     */
    private static function metrics(RowAccumulator $accumulator, array $puzzles): array
    {
        $estimates = [];
        $measured = [];
        $exact = 0;
        $withinOne = 0;
        $average = 0;
        $tiers = new TierScores();

        foreach ($puzzles as [$estimate, , $truth, , , $indices, $p10Offset, $p90Offset]) {
            if ($truth === null) {
                continue;
            }

            $tiers->add($estimate, $truth, $indices, $p10Offset, $p90Offset);
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
            // Puzzles with a row in the group; the puzzle-level numbers count each puzzle in its latest row's group
            'puzzles' => count($accumulator->puzzleIds),
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
            'estimateModels' => $accumulator->estimateModels,
        ] + $tiers->metrics();
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
