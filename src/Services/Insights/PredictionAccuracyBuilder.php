<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsAccuracySection;

/**
 * "How good are my predictions?" - the hit rate of your likely windows. Only predictions of the calibrated model
 * count (model version ≥ CALIBRATED_MODEL_VERSION: its stored range is the 50 % window, so a hit rate means
 * something); before it the range was the old, uncalibrated one, so until MIN_PREDICTIONS calibrated ones exist the
 * section is "not enough yet".
 */
readonly final class PredictionAccuracyBuilder
{
    /**
     * @param list<InsightsRow> $rows the selection
     */
    public function build(array $rows): InsightsAccuracySection
    {
        $count = 0;
        $hits = 0;
        $faster = 0;
        $slower = 0;
        $margins = [];
        $firstTries = 0;
        $firstTryHits = 0;
        $repeats = 0;
        $repeatHits = 0;
        $older = 0;

        foreach ($rows as $row) {
            $result = $row->result;

            if ($result->hasPrediction() === false) {
                continue;
            }

            if (ExpectedVsActualBuilder::isCalibrated($row) === false || $result->predictedRangeLowSeconds === null || $result->predictedRangeHighSeconds === null) {
                $older++;

                continue;
            }

            $count++;
            $hit = $result->seconds >= $result->predictedRangeLowSeconds && $result->seconds <= $result->predictedRangeHighSeconds;
            $margins[] = $result->seconds / (int) $result->predictedSeconds - 1;

            if ($hit) {
                $hits++;
            } elseif ($result->seconds < $result->predictedRangeLowSeconds) {
                $faster++;
            } else {
                $slower++;
            }

            if ($result->firstAttempt) {
                $firstTries++;
                $firstTryHits += $hit ? 1 : 0;
            } else {
                $repeats++;
                $repeatHits += $hit ? 1 : 0;
            }
        }

        $enough = $count >= InsightsAccuracySection::MIN_PREDICTIONS;

        return new InsightsAccuracySection(
            sampleSize: $count,
            hits: $hits,
            hitRate: $enough ? $hits / $count : null,
            fasterThanWindow: $faster,
            slowerThanWindow: $slower,
            medianMargin: $enough ? InsightsMath::median($margins) : null,
            firstTrySampleSize: $firstTries,
            firstTryHits: $firstTryHits,
            repeatSampleSize: $repeats,
            repeatHits: $repeatHits,
            olderPredictionCount: $older,
            notSureYet: $enough === false,
        );
    }
}
