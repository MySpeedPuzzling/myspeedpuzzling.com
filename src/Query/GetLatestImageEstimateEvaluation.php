<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Results\ImageEstimateEvaluationResult;

/**
 * The current gate record of a subject: the latest run of `myspeedpuzzling:evaluate-image-estimates` (subject
 * `image`, the default) or of `myspeedpuzzling:evaluate-prediction-candidate` for one candidate - null before the first.
 */
readonly final class GetLatestImageEstimateEvaluation
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function latest(string $subject = ImageEstimateEvaluation::SUBJECT_IMAGE): null|ImageEstimateEvaluationResult
    {
        // evaluated_at ties (two runs within one clock tick) fall back to the uuid7 id - the later insert
        $row = $this->database->fetchAssociative('
            SELECT ' . ImageEstimateEvaluationResult::COLUMNS . '
            FROM image_estimate_evaluation
            WHERE subject = :subject
            ORDER BY evaluated_at DESC, id DESC
            LIMIT 1
        ', ['subject' => $subject]);

        return $row !== false ? ImageEstimateEvaluationResult::fromDatabaseRow($row) : null;
    }
}
