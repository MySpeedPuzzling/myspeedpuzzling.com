<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimePredictionRecordRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
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
        private PredictionModelParametersProvider $parametersProvider,
    ) {
    }

    /**
     * The shown prediction (puzzle_solving_time) is written for pending and re-evaluated times - and, asked for, over
     * every reconstructed one from before the current fit; never over a live one, that is history. The side-by-side
     * row of the current model is written wherever it is missing, and replaced when the time was re-evaluated (or,
     * asked for, when it is a reconstruction from before the current fit).
     *
     * Before the model's first fit (prediction_model_fit) no side-by-side rows at all: they would hold the defaults'
     * predictions without priors under the current model version, as if fitted - the shown prediction of a pending
     * time is still written (and replaced by the post-deploy `--recompute-reconstructed` run).
     *
     * @return int how many times got a prediction (or "not predictable") - shown or side by side
     */
    public function __invoke(BackfillSolvingTimePredictions $message): int
    {
        $fittedAt = $this->parametersProvider->fittedAt();
        $fitted = $fittedAt !== null;
        // Only reconstructions from before the current fit: a run that stopped continues where it was
        $recomputeBefore = $message->recomputeReconstructed ? $fittedAt : null;

        // Locked before anything is read, so the reconstruction sees every edit committed before it
        $times = $this->puzzleSolvingTimeRepository->findForPredictionOfPlayer($message->playerId, $message->reevaluateTimeIds, $recomputeBefore, withSideRows: $fitted);

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

            if ($reevaluated || self::isReconstructionBefore($time->predictionSource, $time->predictionComputedAt, $recomputeBefore)) {
                $time->recordPrediction($prediction->forDisplay(), $now);
            }

            if ($fitted) {
                $record = $records[$timeId] ?? null;

                if ($record === null) {
                    $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION, $prediction, $now));
                } elseif ($reevaluated || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeBefore)) {
                    $record->record($prediction, $now);
                }
            }

            $recorded++;
        }

        return $recorded;
    }

    private static function isReconstructionBefore(null|TimePredictionSource $source, null|DateTimeImmutable $computedAt, null|DateTimeImmutable $fittedAt): bool
    {
        return $fittedAt !== null
            && $source === TimePredictionSource::Reconstructed
            && $computedAt !== null
            && $computedAt <= $fittedAt;
    }
}
