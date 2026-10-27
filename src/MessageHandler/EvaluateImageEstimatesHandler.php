<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluateImageEstimates;
use SpeedPuzzling\Web\Query\GetImageEstimateEvaluationRows;
use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Repository\ImageEstimateEvaluationRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The gate record of the picture-based estimates (docs/features/insights/image-estimate-gates.md): the stored live and
 * reconstructed (out-of-fold) image / blend / statistical predictions of first tries at k = 0..4 on puzzles added in
 * the last N months, measured per size group × image kind (ImageEstimateEvaluator), appended as the current record that
 * ImageEstimateGate reads. The previous record's open groups carry the honesty hysteresis over. Read only apart from
 * that one row.
 */
#[AsMessageHandler]
readonly final class EvaluateImageEstimatesHandler
{
    public function __construct(
        private GetImageEstimateEvaluationRows $getImageEstimateEvaluationRows,
        private GetLatestImageEstimateEvaluation $getLatestImageEstimateEvaluation,
        private ImageEstimateEvaluationRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EvaluateImageEstimates $message): ImageEstimateEvaluation
    {
        $now = $this->clock->now();
        $newerThan = $now->modify(sprintf('-%d months', max(1, $message->newerThanMonths)));

        $result = ImageEstimateEvaluator::evaluate(
            $this->getImageEstimateEvaluationRows->rows($newerThan, ImageEstimateEvaluator::MAX_K),
            ImageEstimateEvaluator::MEASURED_MIN_INDICES,
            ImageEstimateGate::honestyOpenGroups($this->getLatestImageEstimateEvaluation->latest()),
        );

        $evaluation = new ImageEstimateEvaluation(
            id: Uuid::uuid7(),
            evaluatedAt: $now,
            modelVersion: TimePredictionCalculator::MODEL_VERSION,
            newerThan: $newerThan,
            measuredMinIndices: ImageEstimateEvaluator::MEASURED_MIN_INDICES,
            groups: $result['groups'],
            overall: $result['overall'],
            byK: $result['byK'],
            gateVersion: ImageEstimateEvaluator::GATE_VERSION,
            subject: ImageEstimateEvaluation::SUBJECT_IMAGE,
            splitAt: $result['splitAt'] !== null ? $now->setTimestamp((int) $result['splitAt']) : null,
        );

        $this->repository->save($evaluation);

        return $evaluation;
    }
}
