<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsAccuracySection;
use SpeedPuzzling\Web\Results\Insights\InsightsExpectedPoint;
use SpeedPuzzling\Web\Results\Insights\InsightsExpectedSection;
use SpeedPuzzling\Web\Value\TimePredictionMethod;

/**
 * "Which puzzles went faster or slower than expected?" - every result of the selection with a stored prediction.
 *
 * Two kinds of predictions are stored (docs/features/insights/computation.md "Core sections" §7):
 * - the calibrated model (model version ≥ InsightsAccuracySection::CALIBRATED_MODEL_VERSION, stream 3b): its centre
 *   is corrected for your pace at the time, so expected = the prediction;
 * - older ones: statistical first-try predictions ran ~10 % slow for most players (the age bias), so expected = the
 *   prediction × your usual ratio to it - the median actual ÷ predicted of that method's older predictions in the
 *   selection (from MIN_CALIBRATION results on, else 1.0). That is "your margin", built in.
 */
readonly final class ExpectedVsActualBuilder
{
    public const int MIN_CALIBRATION = 5;

    public const int MIN_RESULTS = 5;

    /**
     * @param list<InsightsRow> $rows the selection
     */
    public function build(array $rows): InsightsExpectedSection
    {
        $predicted = array_values(array_filter($rows, static fn (InsightsRow $row): bool => $row->result->hasPrediction()));

        /** @var array<string, list<float>> $legacyRatios */
        $legacyRatios = [];

        foreach ($predicted as $row) {
            if (self::isCalibrated($row) === false) {
                $legacyRatios[self::methodKey($row)][] = $row->result->seconds / (int) $row->result->predictedSeconds;
            }
        }

        $calibration = [];

        foreach ($legacyRatios as $method => $ratios) {
            $calibration[$method] = count($ratios) >= self::MIN_CALIBRATION ? InsightsMath::median($ratios) : 1.0;
        }

        $points = [];
        $better = [];
        $worse = [];
        $within = 0;
        $calibrated = 0;

        foreach ($predicted as $row) {
            $prediction = (int) $row->result->predictedSeconds;

            if (self::isCalibrated($row)) {
                $expected = $prediction;
                $calibrated++;
            } else {
                $expected = max(1, (int) round($prediction * ($calibration[self::methodKey($row)] ?? 1.0)));
            }

            $deviation = $row->result->seconds / $expected - 1;
            $points[] = new InsightsExpectedPoint($row->result->timeId, $expected, $row->result->seconds, $deviation, $row->result->predictionMethod);

            if ($deviation < -InsightsExpectedSection::THRESHOLD) {
                $better[] = [$deviation, $row, $expected];
            } elseif ($deviation > InsightsExpectedSection::THRESHOLD) {
                $worse[] = [$deviation, $row, $expected];
            } else {
                $within++;
            }
        }

        usort($better, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        usort($worse, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $statistical = $calibration[TimePredictionMethod::Statistical->value] ?? null;

        return new InsightsExpectedSection(
            points: $points,
            betterCount: count($better),
            worseCount: count($worse),
            withinCount: $within,
            sampleSize: count($points),
            calibratedCount: $calibrated,
            calibration: $calibration,
            firstTryMargin: $statistical !== null && count($legacyRatios[TimePredictionMethod::Statistical->value] ?? []) >= self::MIN_CALIBRATION ? 1 - $statistical : null,
            better: array_map(static fn (array $entry) => $entry[1]->toItem($entry[2]), array_slice($better, 0, InsightsExpectedSection::LIST_LIMIT)),
            worse: array_map(static fn (array $entry) => $entry[1]->toItem($entry[2]), array_slice($worse, 0, InsightsExpectedSection::LIST_LIMIT)),
            notSureYet: count($points) < self::MIN_RESULTS,
        );
    }

    public static function isCalibrated(InsightsRow $row): bool
    {
        return ($row->result->predictionModelVersion ?? 0) >= InsightsAccuracySection::CALIBRATED_MODEL_VERSION;
    }

    private static function methodKey(InsightsRow $row): string
    {
        return $row->result->predictionMethod->value ?? 'unknown';
    }
}
