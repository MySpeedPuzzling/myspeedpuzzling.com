<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluatePredictionCandidate;
use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Query\GetPredictionPairRows;
use SpeedPuzzling\Web\Repository\ImageEstimateEvaluationRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionCandidateEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateEvaluator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The gate v2 record of a candidate time model (docs/features/insights/image-estimate-gates.md "Candidate time
 * models"): its stored side-by-side predictions against the reference's, per size group (PredictionCandidateEvaluator),
 * appended to the gate records under the candidate's subject; the previous record of that subject carries the honesty
 * hysteresis over. Read only apart from that one row.
 */
#[AsMessageHandler]
readonly final class EvaluatePredictionCandidateHandler
{
    public function __construct(
        private GetPredictionPairRows $getPredictionPairRows,
        private GetLatestImageEstimateEvaluation $getLatestImageEstimateEvaluation,
        private ImageEstimateEvaluationRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EvaluatePredictionCandidate $message): ImageEstimateEvaluation
    {
        $now = $this->clock->now();
        $subject = PredictionCandidateEvaluator::subject($message->candidate, $message->candidateVersion, $message->reference, $message->referenceVersion);
        $newerThan = $message->newerThanMonths !== null ? $now->modify(sprintf('-%d months', max(1, $message->newerThanMonths))) : null;

        $previous = $this->getLatestImageEstimateEvaluation->latest($subject);
        $openBefore = $previous !== null && $previous->gateVersion >= ImageEstimateEvaluator::GATE_VERSION && $previous->modelVersion === $message->candidateVersion
            ? PredictionCandidateEvaluator::honestyOpenGroups($previous->groups)
            : [];

        $result = PredictionCandidateEvaluator::evaluate(
            $this->getPredictionPairRows->rows(
                $message->candidate,
                $message->candidateVersion,
                $message->reference,
                $message->referenceVersion,
                $now->modify(sprintf('-%d months', max(1, $message->solvedSinceMonths))),
                $newerThan,
            ),
            $openBefore,
        );

        $evaluation = new ImageEstimateEvaluation(
            id: Uuid::uuid7(),
            evaluatedAt: $now,
            modelVersion: $message->candidateVersion,
            newerThan: $newerThan,
            measuredMinIndices: 0,
            groups: $result['groups'],
            overall: $result['overall'],
            byK: [],
            gateVersion: ImageEstimateEvaluator::GATE_VERSION,
            subject: $subject,
            splitAt: $result['splitAt'] !== null ? $now->setTimestamp((int) $result['splitAt']) : null,
        );

        $this->repository->save($evaluation);

        return $evaluation;
    }
}
