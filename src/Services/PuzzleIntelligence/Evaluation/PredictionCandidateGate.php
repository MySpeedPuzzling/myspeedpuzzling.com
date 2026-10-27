<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictorKind;

/**
 * Whether a candidate time model passed gate v2 for a size group (docs/features/insights/image-estimate-gates.md
 * "Candidate time models") - the launch decision of a next MODEL_VERSION reads it; nothing shown asks it. Reads the
 * latest record of the candidate's subject (`myspeedpuzzling:evaluate-prediction-candidate`) and fails closed: no record,
 * a record of other gate rules, no numbers for the group.
 */
readonly final class PredictionCandidateGate
{
    public function __construct(
        private GetLatestImageEstimateEvaluation $getLatestImageEstimateEvaluation,
    ) {
    }

    /**
     * @return list<string> why the candidate does not pass for the group (empty = it passes)
     */
    public function failuresFor(PredictorKind $candidate, int $candidateVersion, PredictorKind $reference, int $referenceVersion, PiecesGroup $sizeGroup): array
    {
        $subject = PredictionCandidateEvaluator::subject($candidate, $candidateVersion, $reference, $referenceVersion);
        $record = $this->getLatestImageEstimateEvaluation->latest($subject);

        if ($record === null) {
            return [sprintf('no gate record of "%s" yet (myspeedpuzzling:evaluate-prediction-candidate)', $subject)];
        }

        if ($record->gateVersion < 2 || $record->modelVersion !== $candidateVersion) {
            return [sprintf('the record of "%s" is not a gate v2 record of model %d', $subject, $candidateVersion)];
        }

        $group = $record->group($sizeGroup->value, PredictionCandidateEvaluator::ANY_KIND);

        if ($group === null) {
            return ['no evaluated predictions in this group'];
        }

        return PredictionCandidateEvaluator::failures($group);
    }
}
