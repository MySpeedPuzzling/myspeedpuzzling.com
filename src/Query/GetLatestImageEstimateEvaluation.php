<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ImageEstimateEvaluationResult;

/**
 * The current gate record of the picture-based estimates: the latest run of `myspeedpuzzling:evaluate-image-estimates`
 * (ImageEstimateEvaluation) - null before the first.
 */
readonly final class GetLatestImageEstimateEvaluation
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function latest(): null|ImageEstimateEvaluationResult
    {
        // evaluated_at ties (two runs within one clock tick) fall back to the uuid7 id - the later insert
        $row = $this->database->fetchAssociative('
            SELECT id, evaluated_at, model_version, newer_than, measured_min_indices, groups, overall, by_k
            FROM image_estimate_evaluation
            ORDER BY evaluated_at DESC, id DESC
            LIMIT 1
        ');

        return $row !== false ? ImageEstimateEvaluationResult::fromDatabaseRow($row) : null;
    }
}
