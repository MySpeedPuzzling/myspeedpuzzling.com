<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * The fitted parameters of the current model version (prediction_model_fit), defaults before the first fit. The
 * read paths of the site take them in a statement they run anyway (SQL as a scalar subquery, :modelVersion =
 * TimePredictionCalculator::MODEL_VERSION) - no query of their own; the batch and the reconstructor ask current().
 */
readonly final class PredictionModelParametersProvider
{
    public const string SQL = 'SELECT parameters FROM prediction_model_fit WHERE model_version = :modelVersion';

    /** The candidate model's fit (v4, never shown) - a scalar subquery like SQL, :candidateModelVersion */
    public const string CANDIDATE_SQL = 'SELECT parameters FROM prediction_model_fit WHERE model_version = :candidateModelVersion';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * When the current model version was last fitted (myspeedpuzzling:fit-prediction-model); null = never - its
     * predictions then run on the defaults without priors, and the backfill writes no side-by-side rows of them.
     */
    public function fittedAt(): null|DateTimeImmutable
    {
        /** @var false|null|string $fittedAt */
        $fittedAt = $this->connection->fetchOne(
            'SELECT fitted_at FROM prediction_model_fit WHERE model_version = :modelVersion',
            ['modelVersion' => TimePredictionCalculator::MODEL_VERSION],
        );

        return is_string($fittedAt) ? new DateTimeImmutable($fittedAt) : null;
    }

    public function current(): PredictionModelParameters
    {
        /** @var false|null|string $json */
        $json = $this->connection->fetchOne(self::SQL, ['modelVersion' => TimePredictionCalculator::MODEL_VERSION]);

        return self::fromJson(is_string($json) ? $json : null);
    }

    /**
     * When the candidate model was last fitted; null = never - then nothing writes candidate rows.
     */
    public function candidateFittedAt(): null|DateTimeImmutable
    {
        /** @var false|null|string $fittedAt */
        $fittedAt = $this->connection->fetchOne(
            'SELECT fitted_at FROM prediction_model_fit WHERE model_version = :modelVersion',
            ['modelVersion' => CandidatePredictionCalculator::MODEL_VERSION],
        );

        return is_string($fittedAt) ? new DateTimeImmutable($fittedAt) : null;
    }

    /**
     * The candidate model's fit (CandidatePredictionCalculator::MODEL_VERSION); null = never fitted - then nothing
     * writes candidate rows.
     */
    public function candidate(): null|CandidateModelParameters
    {
        /** @var false|null|string $json */
        $json = $this->connection->fetchOne(self::CANDIDATE_SQL, ['candidateModelVersion' => CandidatePredictionCalculator::MODEL_VERSION]);

        return self::candidateFromJson(is_string($json) ? $json : null);
    }

    public static function candidateFromJson(null|string $json): null|CandidateModelParameters
    {
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? CandidateModelParameters::fromArray($data) : null;
    }

    public static function fromJson(null|string $json): PredictionModelParameters
    {
        if ($json === null) {
            return PredictionModelParameters::defaults();
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return PredictionModelParameters::defaults();
        }

        return is_array($data) ? PredictionModelParameters::fromArray($data) : PredictionModelParameters::defaults();
    }
}
