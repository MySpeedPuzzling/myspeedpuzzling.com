<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimePredictionRecordRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BackfillSolvingTimePredictionsHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PredictionReconstructor $predictionReconstructor,
        private SolvingTimePredictionRecordRepository $recordRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The shown prediction (puzzle_solving_time) is written for pending and re-evaluated times - and, asked for, for
     * those reconstructed by an older model; never over a live one, that is history. The side-by-side row of the
     * current model is written wherever it is missing, and replaced when the time was re-evaluated.
     *
     * @return int how many times got a prediction (or "not predictable") - shown or side by side
     */
    public function __invoke(BackfillSolvingTimePredictions $message): int
    {
        // Locked before anything is read, so the reconstruction sees every edit committed before it
        $times = $this->puzzleSolvingTimeRepository->findForPredictionOfPlayer($message->playerId, $message->reevaluateTimeIds, $message->recomputeReconstructed);

        if ($times === []) {
            return 0;
        }

        $timeIds = array_map(static fn (PuzzleSolvingTime $time): string => $time->id->toString(), $times);
        $predictions = $this->predictionReconstructor->reconstruct($message->playerId, $timeIds);
        $records = $this->recordRepository->forTimes($timeIds, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION);
        $reevaluate = array_flip($message->reevaluateTimeIds);

        $now = $this->clock->now();
        $recorded = 0;

        foreach ($times as $time) {
            $timeId = $time->id->toString();
            $prediction = $predictions[$timeId] ?? null;

            if ($prediction === null) {
                continue;
            }

            $reevaluated = isset($reevaluate[$timeId]) || $time->isPredictionPending();
            $outdatedReconstruction = $message->recomputeReconstructed
                && $time->predictionSource === TimePredictionSource::Reconstructed
                && $time->predictionModelVersion < TimePredictionCalculator::MODEL_VERSION;

            if ($reevaluated || $outdatedReconstruction) {
                $time->recordPrediction($prediction->forDisplay(), $now);
            }

            $record = $records[$timeId] ?? null;

            if ($record === null) {
                $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION, $prediction, $now));
            } elseif ($reevaluated) {
                $record->record($prediction, $now);
            }

            $recorded++;
        }

        return $recorded;
    }
}
