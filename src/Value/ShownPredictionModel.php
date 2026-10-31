<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * Which model makes the SHOWN prediction (docs/features/puzzle-intelligence/README.md "Step 8", "The shown model switch";
 * env PREDICTION_SHOWN_MODEL, docs/features/feature_flags.md): `v3` = the statistical model (TimePredictionCalculator,
 * the default), `v7` = the candidate model (CandidatePredictionCalculator, model version 7) - on the puzzle page, the
 * bulk readers (collections, the Picker and its SQL twin, API v1), the add-time recap, the live and reconstructed shown
 * columns of puzzle_solving_time, the calibration report and the personal estimates. Both models keep being computed
 * side by side (solving_time_prediction), so switching back is instant: nothing is migrated.
 *
 * What a shown prediction rests on is on its row: prediction_model_version = modelVersion() of the model that made it
 * (3 or 7) - the statistical model's version stays 3 whatever is shown (its side rows, its fit, the image gates). The
 * time verification never follows the switch: it reads the statistical model's predictions (the form check asks for
 * V3, the scan the statistical side row of a time whose shown prediction is a candidate one).
 *
 * An unknown setting falls back to V3 - the shown numbers of before the switch existed.
 */
enum ShownPredictionModel: string
{
    case V3 = 'v3';
    case V7 = 'v7';

    /**
     * The candidate model version V7 shows (CandidatePredictionCalculator::MODEL_VERSION - pinned equal by
     * ShownPredictionModelTest: a candidate bump must decide whether the shown switch follows). The statistical model's
     * MODEL_VERSION must never take this value - a shown row's version tells the two apart.
     */
    public const int CANDIDATE_VERSION = 7;

    public static function fromSetting(null|string $setting): self
    {
        return self::tryFrom(strtolower(trim((string) $setting))) ?? self::V3;
    }

    /**
     * The model that actually makes the shown predictions: V7 only while the candidate is the shown one AND fitted -
     * before its first fit the statistical model stands in (and its rows say so by their version).
     */
    public static function effective(bool $candidateShownAndFitted): self
    {
        return $candidateShownAndFitted ? self::V7 : self::V3;
    }

    public function isCandidate(): bool
    {
        return $this === self::V7;
    }

    /**
     * prediction_model_version of a shown prediction this model makes
     */
    public function modelVersion(): int
    {
        return match ($this) {
            self::V3 => TimePredictionCalculator::MODEL_VERSION,
            self::V7 => self::CANDIDATE_VERSION,
        };
    }

    /**
     * The side-by-side predictor whose rows are this model's (solving_time_prediction.predictor)
     */
    public function predictor(): PredictorKind
    {
        return $this->isCandidate() ? PredictorKind::Candidate : PredictorKind::Statistical;
    }

    /**
     * SQL: the model version of the statistical side row that holds what the statistical model said about a time - the
     * time's own shown version, unless the shown prediction is a candidate one (then the statistical model's current
     * version, written next to it). For readers that must keep reading the statistical model whatever is shown (the
     * time verification's scan, the repeats' residuals): on rows with a statistical shown prediction it is the column
     * itself, so their SQL results do not change.
     */
    public static function statisticalVersionSql(string $timeAlias): string
    {
        return sprintf(
            'CASE WHEN %1$s.prediction_model_version = %2$d THEN %3$d ELSE %1$s.prediction_model_version END',
            $timeAlias,
            self::CANDIDATE_VERSION,
            TimePredictionCalculator::MODEL_VERSION,
        );
    }
}
