<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetTimesAwaitingImagePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimePredictionRecordRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictionsSideBySide;
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
        private GetTimesAwaitingImagePredictions $getTimesAwaitingImagePredictions,
    ) {
    }

    /**
     * The shown prediction (puzzle_solving_time) is written for pending and re-evaluated times - and, asked for, over
     * every reconstructed one from before the current fit; never over a live one, that is history. The side-by-side
     * row of the current model is written wherever it is missing, and replaced when the time was re-evaluated (or,
     * asked for, when it is a reconstruction from before the current fit).
     *
     * The image / blend rows of a first try (Insights plan §5.4) follow the same rules, from the puzzle's out-of-fold
     * estimate only (PredictionReconstructor): written once the puzzle has one, a live row written without an estimate
     * replaced by the reconstruction, a re-evaluated time that is no first try any more (or has no out-of-fold estimate
     * now) loses its rows; with recomputeImage also reconstructions older than the estimate.
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
        $imageTimeIds = $fitted ? $this->getTimesAwaitingImagePredictions->ofPlayer($message->playerId, $message->recomputeImage) : [];

        // Locked before anything is read, so the reconstruction sees every edit committed before it
        $times = $this->puzzleSolvingTimeRepository->findForPredictionOfPlayer($message->playerId, $message->reevaluateTimeIds, $recomputeBefore, withSideRows: $fitted, imageTimeIds: $imageTimeIds);

        if ($times === []) {
            return 0;
        }

        $timeIds = array_map(static fn (PuzzleSolvingTime $time): string => $time->id->toString(), $times);
        $predictions = $this->predictionReconstructor->reconstructSideBySide($message->playerId, $timeIds);
        $records = $fitted
            ? $this->recordRepository->forTimesByPredictor($timeIds, [PredictorKind::Statistical, PredictorKind::Image, PredictorKind::Blend], TimePredictionCalculator::MODEL_VERSION)
            : [];
        $reevaluate = array_flip($message->reevaluateTimeIds);
        $awaitingImage = array_flip($imageTimeIds);

        $now = $this->clock->now();
        $recorded = 0;

        foreach ($times as $time) {
            $timeId = $time->id->toString();
            $sideBySide = $predictions[$timeId] ?? null;

            if ($sideBySide === null) {
                continue;
            }

            $prediction = $sideBySide->statistical;
            $reevaluated = isset($reevaluate[$timeId]) || $time->isPredictionPending();

            if ($reevaluated || self::isReconstructionBefore($time->predictionSource, $time->predictionComputedAt, $recomputeBefore)) {
                $time->recordPrediction($prediction->forDisplay(), $now);
            }

            if ($fitted) {
                $record = $records[PredictorKind::Statistical->value][$timeId] ?? null;

                if ($record === null) {
                    $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION, $prediction, $now));
                } elseif ($reevaluated || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeBefore)) {
                    $record->record($prediction, $now);
                }

                $this->recordImageRows($time, $sideBySide, $records, $reevaluated, isset($awaitingImage[$timeId]), $recomputeBefore, $now);
            }

            $recorded++;
        }

        return $recorded;
    }

    /**
     * @param array<string, array<string, SolvingTimePredictionRecord>> $records predictor => time id => row
     * @param bool $reevaluated pending or named (an edit)
     * @param bool $awaiting listed by GetTimesAwaitingImagePredictions (missing, written live without an estimate, stale)
     */
    private function recordImageRows(
        PuzzleSolvingTime $time,
        PredictionsSideBySide $sideBySide,
        array $records,
        bool $reevaluated,
        bool $awaiting,
        null|DateTimeImmutable $recomputeBefore,
        DateTimeImmutable $now,
    ): void {
        $timeId = $time->id->toString();
        $rows = [
            [PredictorKind::Image, $sideBySide->image],
            [PredictorKind::Blend, $sideBySide->blend],
        ];

        foreach ($rows as [$predictor, $prediction]) {
            $record = $records[$predictor->value][$timeId] ?? null;

            if ($prediction === null) {
                // No image prediction for this time now (a repeat, or no out-of-fold estimate): an edit made what was
                // there obsolete; otherwise the row stays (a live one is history)
                if ($record !== null && $reevaluated) {
                    $this->recordRepository->delete($record);
                }

                continue;
            }

            if ($record === null) {
                $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, $predictor, TimePredictionCalculator::MODEL_VERSION, $prediction, $now, $sideBySide->imageEstimate));
            } elseif ($reevaluated || $awaiting || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeBefore)) {
                $record->record($prediction, $now, $sideBySide->imageEstimate);
            }
        }
    }

    private static function isReconstructionBefore(null|TimePredictionSource $source, null|DateTimeImmutable $computedAt, null|DateTimeImmutable $fittedAt): bool
    {
        return $fittedAt !== null
            && $source === TimePredictionSource::Reconstructed
            && $computedAt !== null
            && $computedAt <= $fittedAt;
    }
}
