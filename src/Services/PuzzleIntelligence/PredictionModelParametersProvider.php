<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use JsonException;
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * The fitted parameters of the current model version (prediction_model_fit), defaults before the first fit. The
 * read paths of the site take them in a statement they run anyway (SQL as a scalar subquery, :modelVersion =
 * TimePredictionCalculator::MODEL_VERSION) - no query of their own; the batch and the reconstructor ask current().
 */
readonly final class PredictionModelParametersProvider
{
    public const string SQL = 'SELECT parameters FROM prediction_model_fit WHERE model_version = :modelVersion';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function current(): PredictionModelParameters
    {
        /** @var false|null|string $json */
        $json = $this->connection->fetchOne(self::SQL, ['modelVersion' => TimePredictionCalculator::MODEL_VERSION]);

        return self::fromJson(is_string($json) ? $json : null);
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
